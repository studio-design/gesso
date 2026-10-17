<?php

declare(strict_types=1);

namespace Studio\Gesso\Internal;

/**
 * Every top-level runtime-expression family named by OpenAPI 3.2 and
 * Arazzo 1.1, keyed by its literal prefix. Knowing all of them lets the
 * parser tell a malformed expression (`$foo`) apart from a recognised one
 * that the current caller cannot evaluate (`$workflows.*`).
 *
 * @internal Runtime-expression plumbing shared by Links exploration and the Arazzo runner; not public API.
 */
enum RuntimeExpressionFamily: string
{
    case Url = '$url';
    case Method = '$method';
    case StatusCode = '$statusCode';
    case Request = '$request';
    case Response = '$response';
    case Message = '$message';
    case Inputs = '$inputs';
    case Outputs = '$outputs';
    case Steps = '$steps';
    case Workflows = '$workflows';
    case SourceDescriptions = '$sourceDescriptions';
    case Components = '$components';
    case Self = '$self';
}
