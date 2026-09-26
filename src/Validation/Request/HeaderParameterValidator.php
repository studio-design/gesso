<?php

declare(strict_types=1);

namespace Studio\Gesso\Validation\Request;

use Studio\Gesso\OpenApiVersion;
use Studio\Gesso\SchemaContext;
use Studio\Gesso\Validation\Support\HeaderNormalizer;
use Studio\Gesso\Validation\Support\HeaderValueValidator;
use Studio\Gesso\Validation\Support\NamedError;
use Studio\Gesso\Validation\Support\SchemaValidatorRunner;

use function is_array;
use function strtolower;

/**
 * @internal Not part of the package's public API. Do not use from user code.
 */
final class HeaderParameterValidator
{
    private readonly HeaderValueValidator $values;

    public function __construct(SchemaValidatorRunner $runner)
    {
        $this->values = new HeaderValueValidator($runner, 'header', SchemaContext::Request);
    }

    /**
     * Validate header parameters declared by the matched operation (or
     * inherited from the path-level `parameters` block).
     *
     * HTTP header names are case-insensitive (RFC 7230) so both the spec
     * `name` and the caller-supplied `$headers` keys are lower-cased before
     * matching. Error messages keep the spec's original casing so users can
     * grep the spec directly.
     *
     * Per OpenAPI 3.x, `Accept`, `Content-Type`, and `Authorization`
     * declarations are ignored — these are controlled by content negotiation
     * and security schemes, not arbitrary header parameters. {@see ParameterCollector}
     * filters those at collection time so they never reach here.
     *
     * Value handling (HeaderBag unwrap, multi-value refusal, scalar guard,
     * coercion) is shared with the response side via {@see HeaderValueValidator}.
     *
     * @param list<array<string, mixed>> $parameters pre-collected merged parameters
     * @param array<array-key, mixed> $headers caller-supplied request headers
     *
     * @return list<NamedError>
     */
    public function validate(
        string $method,
        string $matchedPath,
        array $parameters,
        array $headers,
        OpenApiVersion $version,
        ?string $jsonSchemaDialect = null,
    ): array {
        $errors = [];
        $normalizedHeaders = HeaderNormalizer::normalize($headers);

        foreach ($parameters as $param) {
            if (($param['in'] ?? null) !== 'header') {
                continue;
            }

            /** @var string $name */
            $name = $param['name'];
            $lowerName = strtolower($name);

            $required = ($param['required'] ?? false) === true;

            // Same reasoning as {@see QueryParameterValidator} / {@see PathParameterValidator}:
            // a required parameter without a schema would silently pass every request, so
            // surface it as a hard spec error. Optional entries without a schema have
            // nothing to validate — let them through.
            if (!isset($param['schema']) || !is_array($param['schema'])) {
                if ($required) {
                    $errors[] = new NamedError($name, "[header.{$name}] required parameter has no schema for {$method} {$matchedPath} — cannot validate.");
                }

                continue;
            }

            /** @var array<string, mixed> $schema */
            $schema = $param['schema'];

            $errors = [...$errors, ...$this->values->validate($name, $normalizedHeaders[$lowerName] ?? null, $required, $schema, $version, $jsonSchemaDialect)];
        }

        return $errors;
    }
}
