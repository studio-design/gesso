<?php

declare(strict_types=1);

namespace Studio\Gesso;

/**
 * Output format adapters use when rendering a validation failure message.
 *
 * `Text` is the historical human-readable shape; `Json` prefixes the same
 * one-line header and then emits the versioned document produced by
 * {@see JsonValidationResultRenderer} (issue #282, stage 3). Selection is
 * resolved by {@see ValidationOutput::format()}.
 */
enum ValidationOutputFormat: string
{
    case Text = 'text';
    case Json = 'json';
}
