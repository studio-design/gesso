<?php

declare(strict_types=1);

namespace Studio\Gesso\Laravel;

use LogicException;

use function implode;
use function sprintf;

/**
 * Reads the `gesso` config namespace, which {@see GessoServiceProvider} fills
 * from the root `gesso.php` under the same key paths (`spec.default`,
 * `laravel.auto_assert`, …). The v2 flat keys were removed in Gesso 3.0: one
 * still set — in a published `config/gesso.php` or by a test's
 * `config()->set()` — fails loudly with its replacement instead of being
 * silently ignored.
 *
 * @internal Laravel configuration boundary; the `gesso.php` key names are the covered surface.
 */
final class LaravelConfig
{
    /** v2 flat key => its `gesso.php` replacement. */
    public const REMOVED = [
        'spec_base_path' => 'spec.base_path',
        'default_spec' => 'spec.default',
        'specs' => 'spec.names',
        'strip_prefixes' => 'spec.strip_prefixes',
        'max_errors' => 'validation.max_errors',
        'enforce_discriminator' => 'validation.enforce_discriminator',
        'acknowledged_unvalidatable_schemes' => 'validation.acknowledged_unvalidatable_schemes',
        'skip_response_codes' => 'validation.skip_response_codes',
        'skip_request_validation_response_codes' => 'validation.skip_request_validation_response_codes',
        'auto_assert' => 'laravel.auto_assert',
        'auto_validate_request' => 'laravel.auto_validate_request',
        'auto_inject_dummy_credentials' => 'laravel.auto_inject_dummy_credentials',
        'auto_inject_dummy_bearer' => "laravel.auto_inject_dummy_credentials = 'bearer'",
        'route_parity' => 'laravel.route_parity',
    ];

    /** Static-only reader — no instances. */
    private function __construct() {}

    /**
     * `config('gesso.' . $key)`, after rejecting any removed v2 key.
     *
     * `config()` stays unqualified so the unit tests' namespaced stand-in
     * (tests/Helpers/LaravelConfigMock.php) answers it.
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        self::rejectRemovedKeys();

        return config('gesso.' . $key, $default);
    }

    public static function rejectRemovedKeys(): void
    {
        $removed = [];
        foreach (self::REMOVED as $key => $replacement) {
            if (config('gesso.' . $key) !== null) {
                $removed[] = sprintf("'%s' -> %s", $key, $replacement);
            }
        }
        if ($removed !== []) {
            throw new LogicException(sprintf(
                'Gesso 3.0 removed the flat Laravel config keys; set them in the root gesso.php '
                . '(or as config(\'gesso.<section>.<key>\') in a test) and delete config/gesso.php '
                . '(see UPGRADING.md#configuration): %s.',
                implode(', ', $removed),
            ));
        }
    }
}
