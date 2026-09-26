<?php

declare(strict_types=1);

namespace Studio\Gesso\Validation\Support;

use Studio\Gesso\OpenApiVersion;
use Studio\Gesso\SchemaContext;
use Studio\Gesso\Validation\Request\HeaderParameterValidator;
use Studio\Gesso\Validation\Response\ResponseHeaderValidator;

use function array_key_first;
use function count;
use function get_debug_type;
use function is_array;
use function is_scalar;
use function sprintf;

/**
 * Value-level half of request and response header validation, shared by
 * {@see HeaderParameterValidator} and
 * {@see ResponseHeaderValidator}. Each
 * caller keeps its own spec-shape rules (which entries to visit, no-schema
 * prose, OAS-mandated skips); everything after "we have a schema" is the same
 * decision tree, differing only by the `[<prefix>.<Name>]` error label and
 * the schema conversion context.
 *
 * Header values arriving as `array<string>` (Laravel/Symfony's HeaderBag
 * models repeated occurrences this way) are unwrapped to a single value when
 * the array holds exactly one element. Multi-value arrays against scalar
 * schemas produce a hard error — frameworks disagree on which of the
 * repeated values "wins" (Laravel: first, Symfony: last), so silently
 * picking one would mask a drift the contract test exists to expose. Empty
 * arrays are treated as missing. `style: simple` with `type: array | object`
 * is out of scope.
 *
 * @internal Not part of the package's public API. Do not use from user code.
 */
final class HeaderValueValidator
{
    public function __construct(
        private readonly SchemaValidatorRunner $runner,
        private readonly string $prefix,
        private readonly SchemaContext $context,
    ) {}

    /**
     * @param mixed $rawValue the header value as looked up from the normalized header map (`null` when absent)
     * @param array<string, mixed> $schema
     *
     * @return list<NamedError>
     */
    public function validate(
        string $name,
        mixed $rawValue,
        bool $required,
        array $schema,
        OpenApiVersion $version,
        ?string $jsonSchemaDialect = null,
    ): array {
        $label = "{$this->prefix}.{$name}";

        // `null` and `[]` (empty repeated-header array) both collapse to "missing".
        // A repeated header that was sent zero times is semantically absent.
        if ($rawValue === null || $rawValue === []) {
            return $required ? [new NamedError($name, "[{$label}] required header is missing.", keyword: 'required')] : [];
        }

        if (is_array($rawValue)) {
            // HeaderBag shape: list<string>. Single-element arrays are the common
            // case (Laravel always wraps) — unwrap. Multi-element means the client
            // sent the header more than once; frameworks disagree on which value
            // is "canonical" (Laravel: first, Symfony: last), so silently picking
            // one would mask drift. Surface it so the spec author / client fixes
            // the duplicate.
            if (count($rawValue) > 1) {
                return [new NamedError($name, sprintf(
                    '[%s] multiple values received (count=%d) but schema expects a single value; refusing to pick one silently.',
                    $label,
                    count($rawValue),
                ))];
            }

            $rawValue = $rawValue[array_key_first($rawValue)];
        }

        // Mirror the pre-unwrap missing-header branch for the post-unwrap case:
        // `['X-Foo' => [null]]` is a caller bug shaped identically to an absent
        // header. Letting it flow to coercion would either silently pass against
        // a `nullable` schema or surface as a `/` type mismatch from opis — both
        // hide the root cause.
        if ($rawValue === null) {
            return $required ? [new NamedError($name, "[{$label}] required header is missing.", keyword: 'required')] : [];
        }

        // Guard against caller-side bugs that smuggle a non-scalar (nested array,
        // object, resource) past the unwrap. Without this, opis would report a
        // JSON-Pointer type mismatch that hides the real cause — that the caller
        // never produced a header-shaped value in the first place.
        if (!is_scalar($rawValue)) {
            return [new NamedError($name, sprintf(
                '[%s] value must be a scalar (string|int|bool|float); got %s.',
                $label,
                get_debug_type($rawValue),
            ))];
        }

        $coerced = TypeCoercer::coercePrimitive($rawValue, $schema);

        return $this->runner->validateNamed($name, $label, $schema, $coerced, $version, $this->context, $jsonSchemaDialect);
    }
}
