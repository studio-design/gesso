<?php

declare(strict_types=1);

namespace Studio\Gesso;

/**
 * Direction of validation for a schema conversion. Drives asymmetric handling
 * of OpenAPI's `readOnly` / `writeOnly` markers: `readOnly` properties are
 * forbidden in requests, `writeOnly` properties are forbidden in responses.
 *
 * @internal Used by the internal schema conversion pipeline.
 */
enum SchemaContext
{
    case Request;
    case Response;

    /**
     * The OpenAPI keyword whose presence on a property subschema marks that
     * property as forbidden in this context. Colocating the mapping with the
     * enum keeps the Request↔readOnly / Response↔writeOnly invariant in the
     * type itself; callers check a property via
     * `($schema[$context->forbiddenKeyword()] ?? null) === true`.
     */
    public function forbiddenKeyword(): string
    {
        return match ($this) {
            self::Request => 'readOnly',
            self::Response => 'writeOnly',
        };
    }
}
