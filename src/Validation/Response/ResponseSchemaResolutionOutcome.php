<?php

declare(strict_types=1);

namespace Studio\Gesso\Validation\Response;

/**
 * Outcome of resolving a response schema by `(method, path, status,
 * content type)` through {@see ResponseSchemaResolver} (issue #442).
 *
 * Every way the resolution pipeline can stop short of a converted schema is
 * its own loud case — never a silent `null` — so consumers outside the
 * response validator (the response-payload explorer, #441) cannot mistake
 * "nothing to validate" for "resolved". The response validator maps each
 * case back onto its historical failure / skip / success behavior.
 *
 * @internal Not part of the package's public API. Do not use from user code.
 */
enum ResponseSchemaResolutionOutcome
{
    /** A JSON-compatible media type with a schema was resolved and converted. */
    case Resolved;

    /**
     * A structural spec node above the response entry (`paths`, the path
     * item, the operation, or the `responses` map) is not a JSON object.
     */
    case MalformedSpec;

    /** The request path matched no spec path. */
    case PathNotFound;

    /** The matched path item declares no operation for the method. */
    case MethodNotDefined;

    /** No exact, range (`5XX`), or `default` response key covers the status. */
    case StatusNotDeclared;

    /** The matched `responses[<key>]` entry is not a JSON object. */
    case MalformedResponse;

    /**
     * The response's `content` block, a media-type entry, or its
     * `schema` / `itemSchema` node is not a JSON object.
     */
    case MalformedContent;

    /** The response declares no `content` block (204-style). */
    case NoContent;

    /**
     * The `content` block declares no JSON-compatible media type (and no
     * actual response Content-Type narrowed the lookup to a non-JSON entry).
     */
    case NoJsonContent;

    /** The actual response Content-Type matches no declared media type. */
    case ContentTypeNotDeclared;

    /** The matched media type declares no `schema` to validate against. */
    case MissingSchema;

    /**
     * The matched media type is non-JSON but declares a `schema` this
     * JSON-Schema engine cannot evaluate (issue #254).
     */
    case NonJsonSchema;

    /**
     * The matched media type uses OpenAPI 3.2 `itemSchema` streaming
     * semantics; stream items cannot be resolved to one buffered schema.
     */
    case ItemSchemaStreaming;
}
