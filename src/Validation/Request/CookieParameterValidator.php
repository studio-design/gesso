<?php

declare(strict_types=1);

namespace Studio\Gesso\Validation\Request;

use Studio\Gesso\OpenApiVersion;
use Studio\Gesso\SchemaContext;
use Studio\Gesso\Validation\Support\NamedError;
use Studio\Gesso\Validation\Support\ParameterContent;
use Studio\Gesso\Validation\Support\ParameterStyleDeserializer;
use Studio\Gesso\Validation\Support\SchemaValidatorRunner;
use Studio\Gesso\Validation\Support\TypeCoercer;

use function is_array;

/**
 * @internal Not part of the package's public API. Do not use from user code.
 */
final class CookieParameterValidator
{
    public function __construct(
        private readonly SchemaValidatorRunner $runner,
    ) {}

    /**
     * Validate `in: cookie` parameters declared by the matched operation (or
     * inherited from the path-level `parameters` block) against the
     * framework-parsed cookie map.
     *
     * `form` (the default) and the OpenAPI 3.2 `cookie` style are read the
     * same way: a non-exploded array or object is split on `,`, and an
     * exploded object is gathered from the cookies its schema can hold
     * ({@see ParameterStyleDeserializer}). An exploded array repeats the
     * cookie name, which a name → value map cannot carry, so only one of its
     * values reaches validation.
     *
     * @param list<array<string, mixed>> $parameters pre-collected merged parameters
     * @param array<array-key, mixed> $cookies caller-supplied request cookies
     * @param null|string $scope names the operation in a loader-cached spec; see {@see SchemaValidatorRunner::validateNamed()}
     *
     * @return list<NamedError>
     */
    public function validate(
        string $method,
        string $matchedPath,
        array $parameters,
        array $cookies,
        OpenApiVersion $version,
        ?string $jsonSchemaDialect = null,
        ?string $scope = null,
    ): array {
        $errors = [];

        foreach ($parameters as $param) {
            if (($param['in'] ?? null) !== 'cookie') {
                continue;
            }

            /** @var string $name */
            $name = $param['name'];
            $required = ($param['required'] ?? false) === true;

            $content = ParameterContent::of($param);
            if ($content !== null) {
                [$mediaType, $schema] = $content;
                if (!isset($cookies[$name])) {
                    if ($required) {
                        $errors[] = new NamedError($name, "[cookie.{$name}] required cookie is missing.", keyword: 'required');
                    }
                } elseif ($schema !== null && ParameterContent::isJson($mediaType)) {
                    $errors = [...$errors, ...ParameterContent::validateJson($this->runner, $name, "cookie.{$name}", $cookies[$name], $mediaType, $schema, $version, $jsonSchemaDialect, $scope)];
                }

                continue;
            }

            // Same reasoning as {@see HeaderParameterValidator}: a required
            // parameter without a schema would silently pass every request.
            if (!isset($param['schema']) || !is_array($param['schema'])) {
                if ($required) {
                    $errors[] = new NamedError($name, "[cookie.{$name}] required parameter has no schema for {$method} {$matchedPath} — cannot validate.");
                }

                continue;
            }

            /** @var array<string, mixed> $schema */
            $schema = $param['schema'];

            $value = $cookies[$name] ?? ParameterStyleDeserializer::spreadObject($name, $param, $schema, $cookies, $parameters);
            if ($value === null) {
                if ($required) {
                    $errors[] = new NamedError($name, "[cookie.{$name}] required cookie is missing.", keyword: 'required');
                }

                continue;
            }

            $coerced = TypeCoercer::coerce(ParameterStyleDeserializer::deserialize($value, $param, $schema), $schema);
            $errors = [...$errors, ...$this->runner->validateNamed($name, "cookie.{$name}", $schema, $coerced, $version, SchemaContext::Request, $jsonSchemaDialect, $scope)];
        }

        return $errors;
    }
}
