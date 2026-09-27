<?php

declare(strict_types=1);

namespace Studio\Gesso\Validation\Support;

use const ARRAY_FILTER_USE_KEY;

use function array_change_key_case;
use function array_filter;
use function is_string;

/**
 * @internal Not part of the package's public API. Do not use from user code.
 */
final class HeaderNormalizer
{
    /**
     * Lower-case the keys of the caller-supplied headers map. Non-string keys
     * are skipped — they cannot match any spec name and would cause a
     * TypeError on strtolower(). Values are returned as-is; array/scalar
     * discrimination happens at the validation site so the "how many values"
     * decision is visible there.
     *
     * When two keys collapse to the same lower-case form (e.g. both
     * `X-Foo` and `x-foo` are present), later entries overwrite earlier ones
     * — HTTP treats these as the same header so the behaviour matches what
     * most frameworks surface to application code.
     *
     * @param array<array-key, mixed> $headers
     *
     * @return array<string, mixed>
     */
    public static function normalize(array $headers): array
    {
        return array_change_key_case(array_filter($headers, is_string(...), ARRAY_FILTER_USE_KEY));
    }
}
