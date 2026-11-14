<?php

declare(strict_types=1);

namespace Studio\Gesso\Laravel;

use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository;
use Studio\Gesso\Config\ConfigurationBridge;
use Studio\Gesso\Config\LegacyConfigurationKeys;
use WeakMap;

use function array_key_exists;
use function array_keys;
use function explode;

/**
 * Reads a Laravel setting from its `gesso.php` path (`gesso.validation.max_errors`)
 * first, then from its v2 flat key (`gesso.max_errors`), so a test override
 * written in the 3.0 form already works on 2.x (#795). A flat key that differs
 * from what the provider resolved — set later by a test's `config()->set()` —
 * emits that key's deprecation notice.
 *
 * `config()` stays unqualified so the unit tests' namespaced stand-in
 * (tests/Helpers/LaravelConfigMock.php) answers it.
 *
 * @internal Laravel configuration boundary; the key names are the covered surface.
 */
final class LaravelConfig
{
    /**
     * Flat values as the provider resolved them, per config repository, so a
     * fresh application per test starts from its own snapshot.
     *
     * @var null|WeakMap<Repository, array<string, mixed>>
     */
    private static ?WeakMap $provided = null;

    /** Static-only reader — no instances. */
    private function __construct() {}

    /** Called once the provider has merged `gesso.php` and the package defaults. */
    public static function rememberProvided(Repository $configuration): void
    {
        $values = [];
        foreach (array_keys(ConfigurationBridge::LARAVEL) as $flat) {
            $values[$flat] = $configuration->get('gesso.' . $flat);
        }
        self::$provided ??= new WeakMap();
        self::$provided[$configuration] = $values;
    }

    /** `$flat` is the v2 key without the `gesso.` prefix, e.g. `route_parity.excluded_route_names`. */
    public static function get(string $flat, mixed $default = null): mixed
    {
        $nested = self::nested($flat);
        if ($nested !== null) {
            $value = config($nested);
            if ($value !== null) {
                return $value;
            }
        }

        $value = config('gesso.' . $flat, $default);
        $provided = self::providedValues();
        if ($provided !== null && array_key_exists($flat, $provided) && $value !== $provided[$flat]) {
            LegacyConfigurationKeys::deprecate(explode('.', $flat)[0]);
        }

        return $value;
    }

    /** The full key {@see get()} reads `$flat` from, for error messages. */
    public static function key(string $flat): string
    {
        $nested = self::nested($flat);

        return $nested !== null && config($nested) !== null ? $nested : 'gesso.' . $flat;
    }

    private static function nested(string $flat): ?string
    {
        $mapping = ConfigurationBridge::LARAVEL[$flat] ?? null;

        return $mapping === null ? null : 'gesso.' . $mapping[0];
    }

    /** @return null|array<string, mixed> */
    private static function providedValues(): ?array
    {
        $container = Container::getInstance();
        if (self::$provided === null || !$container->bound('config')) {
            return null;
        }
        $configuration = $container->make('config');

        return $configuration instanceof Repository ? self::$provided[$configuration] ?? null : null;
    }
}
