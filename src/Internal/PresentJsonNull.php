<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Internal;

/**
 * Marker for "the wire carried a body whose decoded JSON value is the literal
 * `null`" — distinct from a plain PHP `null`, which the body validators read
 * as "no body was present at all".
 *
 * Issue #246: the framework adapters decode request / response bodies with
 * `json_decode()`. A body of the four bytes `null` decodes to PHP `null`,
 * indistinguishable from an absent body once it reaches the validators —
 * letting a malformed `null` / scalar body be silently classified as "empty".
 * An adapter that knows the raw content was non-empty wraps a decoded `null`
 * in this marker so the request / response body validators type-check it
 * against the schema instead of short-circuiting as "no body".
 *
 * A single-case enum is used as a value-less singleton: callers detect it
 * with `$body instanceof PresentJsonNull` (an explicit `=== PresentJsonNull::Body`
 * is equivalent). Every code path that reads a decoded body value MUST unwrap
 * this marker — treat it as the value `null` — before passing the value on to
 * schema conversion or the strict-required walker; the marker itself must
 * never reach `opis/json-schema` or user code.
 *
 * @internal Not part of the package's public API. Do not use from user code.
 */
enum PresentJsonNull
{
    case Body;
}
