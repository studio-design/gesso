<?php

declare(strict_types=1);

namespace Studio\Gesso\Validation\Request;

use Studio\Gesso\OpenApiVersion;
use Studio\Gesso\SchemaContext;
use Studio\Gesso\Spec\OpenApiSchemaConverter;
use Studio\Gesso\Validation\Support\HeaderNormalizer;
use Studio\Gesso\Validation\Support\NamedError;
use Studio\Gesso\Validation\Support\ObjectConverter;
use Studio\Gesso\Validation\Support\SchemaValidatorRunner;
use Studio\Gesso\Validation\Support\TypeCoercer;

use function array_key_first;
use function count;
use function get_debug_type;
use function is_array;
use function is_scalar;
use function sprintf;
use function strtolower;

/**
 * @internal Not part of the package's public API. Do not use from user code.
 */
final class HeaderParameterValidator
{
    public function __construct(
        private readonly SchemaValidatorRunner $runner,
    ) {}

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
     * Header values arriving as `array<string>` (Laravel's HeaderBag models
     * repeated occurrences this way) are unwrapped to a single value when the
     * array holds exactly one element. Multi-value arrays against scalar
     * schemas produce a hard error — real frameworks disagree on which of
     * the repeated values "wins" (Laravel picks first, Symfony picks last),
     * so silently picking one would mask a drift the contract test exists to
     * expose. Empty arrays are treated as missing. `style: simple` with
     * `type: array | object` is out of scope.
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

            $rawValue = $normalizedHeaders[$lowerName] ?? null;

            // `null` and `[]` (empty repeated-header array) both collapse to "missing".
            // A repeated header that was sent zero times is semantically absent.
            if ($rawValue === null || $rawValue === []) {
                if ($required) {
                    $errors[] = new NamedError($name, "[header.{$name}] required header is missing.", keyword: 'required');
                }

                continue;
            }

            if (is_array($rawValue)) {
                // HeaderBag shape: list<string>. Single-element arrays are the common
                // case (Laravel always wraps) — unwrap. Multi-element means the client
                // sent the header more than once; frameworks disagree on which value
                // is "canonical" (Laravel: first, Symfony: last), so silently picking
                // one would mask drift. Surface it so the spec author / client fixes
                // the duplicate.
                if (count($rawValue) > 1) {
                    $errors[] = new NamedError($name, sprintf(
                        '[header.%s] multiple values received (count=%d) but schema expects a single value; refusing to pick one silently.',
                        $name,
                        count($rawValue),
                    ));

                    continue;
                }

                $rawValue = $rawValue[array_key_first($rawValue)];
            }

            // Mirror the pre-unwrap missing-header branch for the post-unwrap case:
            // `['X-Foo' => [null]]` is a caller bug shaped identically to an absent
            // header. Letting it flow to coercion would either silently pass against
            // a `nullable` schema or surface as a `/` type mismatch from opis — both
            // hide the root cause.
            if ($rawValue === null) {
                if ($required) {
                    $errors[] = new NamedError($name, "[header.{$name}] required header is missing.", keyword: 'required');
                }

                continue;
            }

            // Guard against caller-side bugs that smuggle a non-scalar (nested array,
            // object, resource) past the unwrap. Without this, opis would report a
            // JSON-Pointer type mismatch that hides the real cause — that the caller
            // never produced a header-shaped value in the first place.
            if (!is_scalar($rawValue)) {
                $errors[] = new NamedError($name, sprintf(
                    '[header.%s] value must be a scalar (string|int|bool|float); got %s.',
                    $name,
                    get_debug_type($rawValue),
                ));

                continue;
            }

            $coerced = TypeCoercer::coercePrimitive($rawValue, $schema);
            $jsonSchema = OpenApiSchemaConverter::convert($schema, $version, SchemaContext::Request, null, $jsonSchemaDialect);

            $schemaObject = ObjectConverter::convert($jsonSchema);
            $dataObject = ObjectConverter::convert($coerced);

            foreach ($this->runner->validateStructured($schemaObject, $dataObject) as $violation) {
                $suffix = $violation->displayPath() === '/' ? '' : $violation->displayPath();
                $errors[] = new NamedError($name, "[header.{$name}{$suffix}] {$violation->message}", $violation->instancePath, $violation->keyword);
            }
        }

        return $errors;
    }
}
