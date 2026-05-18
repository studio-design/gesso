<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting;

/**
 * Envelope for a request / response body after JSON decoding, carrying the
 * absent-vs-present distinction as a single value.
 *
 * A decoded body is one of four shapes — a JSON object/array, a JSON scalar,
 * the literal JSON `null`, or no body at all. PHP's `json_decode()` collapses
 * the last two: a body of the four bytes `null` and an absent body both
 * decode to PHP `null`. Passing the decoded value around as a bare `mixed`
 * therefore loses the "was a body present?" bit — the gap issue #246 first
 * patched with an internal marker enum and issue #248 closes properly here.
 *
 * `present` records whether the wire carried a body; `value` is the decoded
 * value (always `null` when `present` is false). A literal-null body is
 * `present === true` with `value === null` — exactly the state a bare `null`
 * could not express.
 *
 * The framework adapters build this envelope; the body validators consume it.
 * The public `OpenApiResponseValidator::validate()` /
 * `OpenApiRequestValidator::validate()` still accept a `mixed` body for
 * backward compatibility and normalize it through {@see self::fromLegacy()}.
 */
final readonly class DecodedBody
{
    /**
     * @param mixed $value the decoded JSON body value — an `array`, `string`,
     *                     `int`, `float`, `bool`, or `null`. Always `null`
     *                     when `$present` is false. Typed `mixed` rather than
     *                     a union because the public validators accept a bare
     *                     legacy body of any shape via {@see self::fromLegacy()}.
     */
    private function __construct(
        public bool $present,
        public mixed $value,
    ) {}

    /**
     * No body was carried on the wire.
     */
    public static function absent(): self
    {
        return new self(false, null);
    }

    /**
     * A body was carried on the wire; `$value` is its decoded value (which may
     * itself be `null` for a literal JSON `null` body).
     */
    public static function present(mixed $value): self
    {
        return new self(true, $value);
    }

    /**
     * Normalize a legacy `mixed` body argument into a {@see DecodedBody}.
     *
     * An existing {@see DecodedBody} passes through unchanged. Otherwise the
     * historical convention is preserved: a plain PHP `null` means "no body
     * was present", any other value means "this body was present". This keeps
     * the `mixed` body parameter of the public validators backward compatible
     * — callers that never pass `null` for "present" lose nothing, and the
     * marker that previously expressed "present null" was internal-only.
     */
    public static function fromLegacy(mixed $body): self
    {
        if ($body instanceof self) {
            return $body;
        }

        return $body === null ? self::absent() : self::present($body);
    }
}
