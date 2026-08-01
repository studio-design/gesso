<?php

declare(strict_types=1);

namespace Studio\Gesso\Fuzz;

/**
 * The kind of composition choice point a schema node exposes to generation.
 *
 * @internal Not part of the package's public API. Do not use from user code.
 */
enum SchemaChoicePointKind: string
{
    /** A `oneOf` keyword; branches are the filtered branch indexes. */
    case OneOf = 'oneOf';

    /** An `anyOf` keyword; branches are the filtered branch indexes. */
    case AnyOf = 'anyOf';

    /** Conditional `allOf` entries; branches index the conditional list. */
    case AllOfConditional = 'allOfConditional';

    /** An `if`/`then`/`else` keyword; branch 0 takes `then`, 1 takes `else`. */
    case IfThenElse = 'ifThenElse';

    /** A nullable type array; branch 0 keeps the value, 1 emits null. */
    case Nullable = 'nullable';

    /** An optional object property; branch 0 includes it, 1 omits it. */
    case OptionalProperty = 'optionalProperty';
}
