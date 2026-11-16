<?php

declare(strict_types=1);

namespace Studio\Gesso\Validation\Request;

use const E_USER_WARNING;

use Studio\Gesso\OpenApiVersion;
use Studio\Gesso\SchemaContext;
use Studio\Gesso\Validation\Support\NamedError;
use Studio\Gesso\Validation\Support\ParameterContent;
use Studio\Gesso\Validation\Support\ParameterStyleDeserializer;
use Studio\Gesso\Validation\Support\SchemaValidatorRunner;
use Studio\Gesso\Validation\Support\TypeCoercer;

use function count;
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
     * With the OpenAPI default serialization (`style: form` + `explode: true`)
     * repeated keys (`?tags=a&tags=b`) are expected to arrive as PHP arrays
     * from the framework. Non-exploded array serializations (`form` +
     * `explode: false`, `pipeDelimited`, `spaceDelimited`) are split on their
     * delimiter before validation, and so are non-exploded objects
     * ({@see ParameterStyleDeserializer}). Exploded `form` and `deepObject`
     * objects are rebuilt from their per-property keys ({@see ParameterStyleDeserializer::spreadObject()}).
     *
     * @param list<array<string, mixed>> $parameters pre-collected merged parameters (path + operation level)
     * @param array<string, mixed> $queryParams
     * @param null|string $rawQueryString the request's percent-encoded query string, when the caller has access to it; lets non-exploded styles split before decoding ({@see ParameterStyleDeserializer})
     * @param null|string $scope names the operation in a loader-cached spec; see {@see SchemaValidatorRunner::validateNamed()}
     *
     * @return list<NamedError>
     */
    public function validate(
        string $method,
        string $matchedPath,
        array $parameters,
        array $queryParams,
        OpenApiVersion $version,
        ?string $jsonSchemaDialect = null,
        ?string $rawQueryString = null,
        ?string $scope = null,
    ): array {
        $errors = [];
        $rawValues = $rawQueryString === null ? [] : ParameterStyleDeserializer::parseRawValues($rawQueryString);

        foreach ($parameters as $param) {
            if (($param['in'] ?? null) === 'querystring') {
                $errors = [
                    ...$errors,
                    ...$this->validateWholeQueryString($method, $matchedPath, $param, $queryParams, $version, $jsonSchemaDialect),
                ];

                continue;
            }

            if (($param['in'] ?? null) !== 'query') {
                continue;
            }

            /** @var string $name */
            $name = $param['name'];
            $required = ($param['required'] ?? false) === true;

            $content = ParameterContent::of($param);
            if ($content !== null) {
                [$mediaType, $schema] = $content;
                if (!isset($queryParams[$name])) {
                    if ($required) {
                        $errors[] = new NamedError($name, "[query.{$name}] required query parameter is missing.", keyword: 'required');
                    }
                } elseif ($schema !== null && ParameterContent::isJson($mediaType)) {
                    $errors = [...$errors, ...ParameterContent::validateJson($this->runner, $name, "query.{$name}", $queryParams[$name], $mediaType, $schema, $version, $jsonSchemaDialect, $scope)];
                }

                continue;
            }

            // A required parameter with no schema is a clearly malformed spec — surface it
            // rather than silently passing every request. Optional parameters with no schema
            // have nothing to validate, so we let them through (matches {@see RequestBodyValidator}).
            if (!isset($param['schema']) || !is_array($param['schema'])) {
                if ($required) {
                    $errors[] = new NamedError($name, "[query.{$name}] required parameter has no schema for {$method} {$matchedPath} — cannot validate.");
                }

                continue;
            }

            /** @var array<string, mixed> $schema */
            $schema = $param['schema'];

            $value = $queryParams[$name] ?? ParameterStyleDeserializer::spreadObject($name, $param, $schema, $queryParams, $parameters);
            if ($value === null) {
                if ($required) {
                    $errors[] = new NamedError($name, "[query.{$name}] required query parameter is missing.", keyword: 'required');
                }

                continue;
            }

            // A single raw pair can be split before percent-decoding; repeated
            // keys mean the exploded form was used and there is nothing to split.
            $rawList = $rawValues[$name] ?? null;
            $rawValue = $rawList !== null && count($rawList) === 1 ? $rawList[0] : null;

            $deserialized = ParameterStyleDeserializer::deserialize($value, $param, $schema, $rawValue);
            $coerced = TypeCoercer::coerce($deserialized, $schema);
            $errors = [...$errors, ...$this->runner->validateNamed($name, "query.{$name}", $schema, $coerced, $version, SchemaContext::Request, $jsonSchemaDialect, $scope)];
        }

        return $errors;
    }

    /**
     * @param array<string, mixed> $parameter
     * @param array<string, mixed> $queryParams
     *
     * @return list<NamedError>
     */
    private function validateWholeQueryString(
        string $method,
        string $matchedPath,
        array $parameter,
        array $queryParams,
        OpenApiVersion $version,
        ?string $jsonSchemaDialect,
    ): array {
        // OpenAPI 3.2 `in: querystring` parameters are named objects too;
        // carry the name so baseline fingerprints can distinguish them.
        $qsName = $parameter['name'] ?? null;
        $qsName = is_string($qsName) ? $qsName : null;

        // ParameterCollector already rejected both, neither, and a content map
        // without exactly one media type.
        $content = ParameterContent::of($parameter);
        if ($content === null) {
            return [new NamedError($qsName, "[querystring] parameter must use content instead of schema for {$method} {$matchedPath}.")];
        }

        [$mediaType, $schema] = $content;
        if (strtolower($mediaType) !== 'application/x-www-form-urlencoded') {
            if (!isset(self::$warnedUnsupportedQueryStringMediaTypes[$mediaType])) {
                self::$warnedUnsupportedQueryStringMediaTypes[$mediaType] = true;
                trigger_error(
                    sprintf(
                        '[OpenAPI 3.2 querystring] %s %s declares unsupported query-string media type(s): %s. '
                        . 'Only application/x-www-form-urlencoded can be reconstructed from the parsed query map; validation was skipped.',
                        $method,
                        $matchedPath,
                        $mediaType,
                    ),
                    E_USER_WARNING,
                );
            }

            return [];
        }

        if ($schema === null) {
            return [new NamedError($qsName, "[querystring] content '{$mediaType}' has no schema for {$method} {$matchedPath} — cannot validate.")];
        }

        if ($queryParams === []) {
            return ($parameter['required'] ?? false) === true
                ? [new NamedError($qsName, "[querystring] required URL query string is missing for {$method} {$matchedPath}.", keyword: 'required')]
                : [];
        }

        $coerced = $queryParams;
        $properties = $schema['properties'] ?? null;
        if (is_array($properties)) {
            foreach ($coerced as $name => $value) {
                $propertySchema = $properties[$name] ?? null;
                if (is_array($propertySchema)) {
                    $coerced[$name] = TypeCoercer::coerce($value, $propertySchema);
                }
            }
        }

        return $this->runner->validateNamed($qsName, 'querystring', $schema, $coerced, $version, SchemaContext::Request, $jsonSchemaDialect);
    }
}
