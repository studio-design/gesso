<?php

declare(strict_types=1);

namespace Studio\Gesso\Internal;

/**
 * Why a runtime expression could not produce a value. Each case is a loud
 * failure: an expression never silently resolves to `null` or to its own
 * literal text.
 *
 * @internal Runtime-expression plumbing for Links exploration; not public API.
 */
enum RuntimeExpressionFailure
{
    /** The text does not match the runtime-expression grammar. */
    case Malformed;

    /** A recognised family the current caller does not evaluate. */
    case UnsupportedFamily;

    /** The context carries no request, response, or inputs to read from. */
    case ContextUnavailable;

    /** The context exists but the referenced member does not. */
    case NotFound;

    /** An embedded `{expression}` produced an array or object. */
    case NonScalarEmbedded;
}
