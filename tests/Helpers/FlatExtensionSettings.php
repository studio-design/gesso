<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Helpers;

use LogicException;
use Studio\Gesso\Config\ConfigurationBridge;
use Studio\Gesso\Config\GessoConfig;
use Studio\Gesso\PHPUnit\ExtensionParameters;

use function array_map;
use function array_replace_recursive;
use function array_reverse;
use function explode;
use function getcwd;
use function is_string;

/**
 * Builds the extension's settings from the internal names in
 * {@see ConfigurationBridge::PHPUNIT} (the v2 parameter spellings) by writing
 * each to its `gesso.php` key, so the extension tests keep one flat array per
 * case. Comma-separated list strings are split the way v2 phpunit.xml values
 * were; relative paths resolve against the working directory.
 */
final class FlatExtensionSettings
{
    /** @param array<string, bool|float|int|list<string>|string> $flat */
    public static function parameters(array $flat): ExtensionParameters
    {
        return ExtensionParameters::fromConfig(self::config($flat));
    }

    /** @param array<string, bool|float|int|list<string>|string> $flat */
    public static function config(array $flat): GessoConfig
    {
        return GessoConfig::fromArray(self::nested($flat), getcwd() ?: '.');
    }

    /**
     * The `gesso.php` array the flat settings describe.
     *
     * @param array<string, bool|float|int|list<string>|string> $flat
     *
     * @return array<string, mixed>
     */
    public static function nested(array $flat): array
    {
        $nested = [];
        foreach ($flat as $name => $value) {
            [$key, $accessor] = ConfigurationBridge::PHPUNIT[$name] ?? throw new LogicException('Unknown extension setting: ' . $name);
            if ($accessor === 'strings' && is_string($value)) {
                $value = $value === '' ? [] : array_map('trim', explode(',', $value));
            }
            foreach (array_reverse(explode('.', $key)) as $segment) {
                $value = [$segment => $value];
            }
            $nested = array_replace_recursive($nested, $value);
        }

        return $nested;
    }
}
