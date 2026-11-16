<?php

declare(strict_types=1);

namespace Studio\Gesso\Validation\Request;

use const E_USER_WARNING;

use Opis\JsonSchema\Helper;
use Studio\Gesso\OpenApiVersion;
use Studio\Gesso\SchemaContext;
use Studio\Gesso\Validation\Support\MalformedSpecNode;
use Studio\Gesso\Validation\Support\NamedError;
use Studio\Gesso\Validation\Support\ParameterStyleDeserializer;
use Studio\Gesso\Validation\Support\SchemaValidatorRunner;
use Studio\Gesso\Validation\Support\TypeCoercer;

use function array_key_exists;
use function array_keys;
use function count;
use function implode;
use function is_array;
use function is_string;
use function preg_match;
use function sprintf;
use function str_ends_with;
use function str_starts_with;
use function strlen;
use function strtolower;
use function substr;
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
     * objects are rebuilt from their per-property keys ({@see self::spreadObject()}).
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

            $value = $queryParams[$name] ?? self::spreadObject($name, $param, $schema, $queryParams, $parameters);
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
     * Rebuild an object parameter whose properties travel as keys of their
     * own: exploded `form` (`R=100&G=200`) and `deepObject` (`color[R]=100`)
     * when the query map was not parsed with PHP's bracket syntax — the
     * client-side PSR-7 adapter and the MockHttpClient fake keep `color[R]`
     * as a flat key. Frameworks hand a `deepObject` over already nested,
     * under `$name`.
     *
     * An exploded `form` object owns every key that no other query parameter
     * of the operation declares and that its schema can hold as a property
     * ({@see self::admitsProperty()}). Two such objects that can both hold a
     * key each receive it: OpenAPI leaves that serialization ambiguous.
     *
     * @param array<string, mixed> $parameter
     * @param array<string, mixed> $schema
     * @param array<string, mixed> $queryParams
     * @param list<array<string, mixed>> $parameters every parameter of the operation
     *
     * @return null|array<string, mixed> `null` when no property was sent
     */
    private static function spreadObject(string $name, array $parameter, array $schema, array $queryParams, array $parameters): ?array
    {
        if (!TypeCoercer::isObjectSchema($schema)) {
            return null;
        }

        $style = $parameter['style'] ?? 'form';
        $object = [];
        if ($style === 'deepObject') {
            foreach ($queryParams as $key => $value) {
                $key = (string) $key;
                if (str_starts_with($key, "{$name}[") && str_ends_with($key, ']')) {
                    $object[substr($key, strlen($name) + 1, -1)] = $value;
                }
            }
        } elseif ($style === 'form' && ($parameter['explode'] ?? true) === true) {
            $others = [];
            foreach ($parameters as $other) {
                if (($other['in'] ?? null) === 'query' && is_string($other['name'] ?? null) && $other['name'] !== $name) {
                    $others[] = $other['name'];
                }
            }

            foreach ($queryParams as $key => $value) {
                $key = (string) $key;
                if (!self::claimedByOther($key, $others) && self::admitsProperty($schema, $key)) {
                    $object[$key] = $value;
                }
            }
        }

        return $object === [] ? null : $object;
    }

    /**
     * Whether another query parameter declares the key, directly or as one
     * of its `deepObject` members (`filter[age]`).
     *
     * @param list<string> $others
     */
    private static function claimedByOther(string $key, array $others): bool
    {
        foreach ($others as $other) {
            if ($key === $other || (str_starts_with($key, "{$other}[") && str_ends_with($key, ']'))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the object schema can hold `$key` as a property: it is declared
     * in `properties`, matches a `patternProperties` pattern (with the regex
     * conversion opis applies), or neither `additionalProperties` nor
     * `unevaluatedProperties` is `false`. `allOf` branches all constrain the
     * same object, so every one of them must admit it.
     *
     * @param array<string, mixed> $schema
     */
    private static function admitsProperty(array $schema, string $key): bool
    {
        $properties = $schema['properties'] ?? null;
        $admitted = is_array($properties) && array_key_exists($key, $properties);

        $patterns = $schema['patternProperties'] ?? null;
        foreach (is_array($patterns) ? $patterns : [] as $pattern => $_) {
            if ($admitted) {
                break;
            }
            $admitted = @preg_match(Helper::patternToRegex((string) $pattern), $key) === 1;
        }

        $admitted = $admitted || (($schema['additionalProperties'] ?? true) !== false && ($schema['unevaluatedProperties'] ?? true) !== false);
        if (!$admitted) {
            return false;
        }

        $branches = $schema['allOf'] ?? null;
        foreach (is_array($branches) ? $branches : [] as $branch) {
            if (is_array($branch) && !self::admitsProperty($branch, $key)) {
                return false;
            }
        }

        return true;
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

        $content = $parameter['content'] ?? null;
        if (MalformedSpecNode::isMalformed($content) || $content === []) {
            return [new NamedError($qsName, "[querystring] parameter has no content map for {$method} {$matchedPath} — cannot validate.")];
        }
        if (array_key_exists('schema', $parameter)) {
            return [new NamedError($qsName, "[querystring] parameter must use content instead of schema for {$method} {$matchedPath}.")];
        }
        if (count($content) !== 1) {
            return [new NamedError($qsName, "[querystring] content must contain exactly one media type for {$method} {$matchedPath}.")];
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
            return [new NamedError($qsName, "[querystring] content '{$mediaType}' must be an object for {$method} {$matchedPath}.")];
        }

        $schema = $mediaTypeSpec['schema'] ?? null;
        if (MalformedSpecNode::isMalformed($schema)) {
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
