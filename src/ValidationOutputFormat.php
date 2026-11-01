<?php

declare(strict_types=1);

namespace Studio\Gesso;

/**
 * Output format adapters use when rendering a validation failure message.
 *
 * `Text` is the historical human-readable shape; `Json` prefixes the same
 * one-line header and then emits the versioned document produced by
 * {@see JsonValidationResultRenderer} (issue #282, stage 3); `Agent` is the
 * compact one-line-per-issue shape for AI coding agents. `Auto`, the
 * default, is a selection rather than a shape: {@see ValidationOutput::format()}
 * resolves it to `Agent` when a coding agent is detected and to `Text`
 * otherwise, and never returns it.
 */
enum ValidationOutputFormat: string
{
    case Text = 'text';
    case Json = 'json';
    case Agent = 'agent';
    case Auto = 'auto';
}
