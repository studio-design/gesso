<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Validation\Request;

use const E_USER_WARNING;

use Studio\OpenApiContractTesting\OpenApiVersion;
use Studio\OpenApiContractTesting\SchemaContext;
use Studio\OpenApiContractTesting\Spec\OpenApiSchemaConverter;
use Studio\OpenApiContractTesting\Validation\Support\MalformedSpecNode;
use Studio\OpenApiContractTesting\Validation\Support\ObjectConverter;
use Studio\OpenApiContractTesting\Validation\Support\SchemaValidatorRunner;
use Studio\OpenApiContractTesting\Validation\Support\TypeCoercer;

use function array_key_exists;
use function array_keys;
use function count;
use function implode;
use function is_array;
use function is_string;
use function sprintf;
use function strtolower;
use function trigger_error;

/**
 * @internal Not part of the package's public API. Do not use from user code.
 */
final class QueryParameterValidator
{
    /** @var array<string, true> */
    private static array $warnedUnsupportedQueryStringMediaTypes = [];

    public function __construct(
        private readonly SchemaValidatorRunner $runner,
    ) {}

    /** @internal Test seam for the process-wide warning ledger. */
    public static function resetWarningStateForTesting(): void
    {
        self::$warnedUnsupportedQueryStringMediaTypes = [];
    }

    /**
     * Validate query parameters declared by the matched operation (or
     * inherited from the path-level `parameters` block).
     *
     * Only `style: form` + `explode: true` (the OpenAPI default for `in: query`)
     * is supported. Repeated keys (`?tags=a&tags=b`) are expected to arrive as
     * PHP arrays from the framework. Other styles (`form`+`explode:false`,
     * `pipeDelimited`, `spaceDelimited`) are out of scope.
     *
     * @param list<array<string, mixed>> $parameters pre-collected merged parameters (path + operation level)
     * @param array<string, mixed> $queryParams
     *
     * @return string[]
     */
    public function validate(
        string $method,
        string $matchedPath,
        array $parameters,
        array $queryParams,
        OpenApiVersion $version,
    ): array {
        $errors = [];

        foreach ($parameters as $param) {
            if (($param['in'] ?? null) === 'querystring') {
                $errors = [
                    ...$errors,
                    ...$this->validateWholeQueryString($method, $matchedPath, $param, $queryParams, $version),
                ];

                continue;
            }

            if (($param['in'] ?? null) !== 'query') {
                continue;
            }

            /** @var string $name */
            $name = $param['name'];
            $required = ($param['required'] ?? false) === true;

            // A required parameter with no schema is a clearly malformed spec — surface it
            // rather than silently passing every request. Optional parameters with no schema
            // have nothing to validate, so we let them through (matches {@see RequestBodyValidator}).
            if (!isset($param['schema']) || !is_array($param['schema'])) {
                if ($required) {
                    $errors[] = "[query.{$name}] required parameter has no schema for {$method} {$matchedPath} — cannot validate.";
                }

                continue;
            }

            /** @var array<string, mixed> $schema */
            $schema = $param['schema'];

            $present = array_key_exists($name, $queryParams) && $queryParams[$name] !== null;
            if (!$present) {
                if ($required) {
                    $errors[] = "[query.{$name}] required query parameter is missing.";
                }

                continue;
            }

            $coerced = TypeCoercer::coerceQuery($queryParams[$name], $schema);
            $jsonSchema = OpenApiSchemaConverter::convert($schema, $version, SchemaContext::Request);

            $schemaObject = ObjectConverter::convert($jsonSchema);
            $dataObject = ObjectConverter::convert($coerced);

            $formatted = $this->runner->validate($schemaObject, $dataObject);
            foreach ($formatted as $path => $messages) {
                $suffix = $path === '/' ? '' : $path;
                foreach ($messages as $message) {
                    $errors[] = "[query.{$name}{$suffix}] {$message}";
                }
            }
        }

        return $errors;
    }

    /**
     * @param array<string, mixed> $parameter
     * @param array<string, mixed> $queryParams
     *
     * @return string[]
     */
    private function validateWholeQueryString(
        string $method,
        string $matchedPath,
        array $parameter,
        array $queryParams,
        OpenApiVersion $version,
    ): array {
        $content = $parameter['content'] ?? null;
        if (MalformedSpecNode::isMalformed($content) || $content === []) {
            return ["[querystring] parameter has no content map for {$method} {$matchedPath} — cannot validate."];
        }
        if (array_key_exists('schema', $parameter)) {
            return ["[querystring] parameter must use content instead of schema for {$method} {$matchedPath}."];
        }
        if (count($content) !== 1) {
            return ["[querystring] content must contain exactly one media type for {$method} {$matchedPath}."];
        }

        $mediaType = null;
        $mediaTypeSpec = null;
        foreach ($content as $candidate => $candidateSpec) {
            if (!is_string($candidate) || strtolower($candidate) !== 'application/x-www-form-urlencoded') {
                continue;
            }

            $mediaType = $candidate;
            $mediaTypeSpec = $candidateSpec;
            break;
        }

        if ($mediaType === null) {
            $declared = implode(', ', array_keys($content));
            if (!isset(self::$warnedUnsupportedQueryStringMediaTypes[$declared])) {
                self::$warnedUnsupportedQueryStringMediaTypes[$declared] = true;
                trigger_error(
                    sprintf(
                        '[OpenAPI 3.2 querystring] %s %s declares unsupported query-string media type(s): %s. '
                        . 'Only application/x-www-form-urlencoded can be reconstructed from the parsed query map; validation was skipped.',
                        $method,
                        $matchedPath,
                        $declared,
                    ),
                    E_USER_WARNING,
                );
            }

            return [];
        }

        if (MalformedSpecNode::isMalformed($mediaTypeSpec)) {
            return ["[querystring] content '{$mediaType}' must be an object for {$method} {$matchedPath}."];
        }

        $schema = $mediaTypeSpec['schema'] ?? null;
        if (MalformedSpecNode::isMalformed($schema)) {
            return ["[querystring] content '{$mediaType}' has no schema for {$method} {$matchedPath} — cannot validate."];
        }

        if ($queryParams === []) {
            return ($parameter['required'] ?? false) === true
                ? ["[querystring] required URL query string is missing for {$method} {$matchedPath}."]
                : [];
        }

        $coerced = $queryParams;
        $properties = $schema['properties'] ?? null;
        if (is_array($properties)) {
            foreach ($coerced as $name => $value) {
                $propertySchema = $properties[$name] ?? null;
                if (is_array($propertySchema)) {
                    $coerced[$name] = TypeCoercer::coerceQuery($value, $propertySchema);
                }
            }
        }

        $jsonSchema = OpenApiSchemaConverter::convert($schema, $version, SchemaContext::Request);
        $formatted = $this->runner->validate(
            ObjectConverter::convert($jsonSchema),
            ObjectConverter::convert($coerced),
        );

        $errors = [];
        foreach ($formatted as $path => $messages) {
            $suffix = $path === '/' ? '' : $path;
            foreach ($messages as $message) {
                $errors[] = "[querystring{$suffix}] {$message}";
            }
        }

        return $errors;
    }
}
