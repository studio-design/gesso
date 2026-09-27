<?php

declare(strict_types=1);

namespace Studio\Gesso\Internal;

use function array_filter;
use function array_map;
use function array_values;
use function explode;
use function in_array;
use function is_int;
use function str_contains;
use function str_replace;
use function str_starts_with;
use function substr;

/**
 * The `--name=value` tokenizer shared by the `bin/gesso` subcommands.
 *
 * Every command declares its options by kind and gets back one array: `help`
 * when `--help` / `-h` was given, `invalid_options` (positional arguments and
 * unknown `--flags`, in the spelling the user typed), and each recognised
 * option under its snake_case name. Lists are always present, so a command
 * can spread them without an `?? []`.
 *
 * @internal Not part of the package's public API. Do not use from user code.
 */
final class ArgvParser
{
    private function __construct() {}

    /**
     * @param list<string> $argv excluding the script name
     * @param string $command the subcommand name, skipped wherever it appears
     * @param list<string> $flags booleans: bare `--x` and `--x=1` are true; `--x=0`, `false`, `no` are false
     * @param list<string> $values kept as the raw string; a repeat overwrites
     * @param array<int|string, string> $lists comma-separated; blanks dropped, repeats accumulate. A string key is the
     *                                         flag spelling when it differs from the option name (`'spec' => 'specs'`)
     *
     * @return array<string, mixed>
     */
    public static function parse(array $argv, string $command, array $flags = [], array $values = [], array $lists = []): array
    {
        $options = [];
        $listKeys = [];
        foreach ($lists as $flag => $key) {
            $listKeys[is_int($flag) ? $key : $flag] = $key;
            $options[$key] = [];
        }
        $options['invalid_options'] = [];

        foreach ($argv as $arg) {
            if ($arg === $command) {
                continue;
            }
            if ($arg === '--help' || $arg === '-h') {
                $options['help'] = true;

                continue;
            }
            if (!str_starts_with($arg, '--')) {
                $options['invalid_options'][] = $arg;

                continue;
            }

            $option = substr($arg, 2);
            [$name, $value] = str_contains($option, '=') ? explode('=', $option, 2) : [$option, 'true'];
            $name = str_replace('-', '_', $name);

            if (in_array($name, $flags, true)) {
                $options[$name] = self::bool($value);
            } elseif (isset($listKeys[$name])) {
                $options[$listKeys[$name]] = [...$options[$listKeys[$name]], ...self::csv($value)];
            } elseif (in_array($name, $values, true)) {
                $options[$name] = $value;
            } else {
                $options['invalid_options'][] = '--' . str_replace('_', '-', $name);
            }
        }

        return $options;
    }

    /**
     * The one boolean spelling the CLI flags, the extension's XML parameters
     * and `GESSO_BASELINE_GENERATE` share: only `0` / `false` / `no` are false.
     */
    public static function bool(string $value): bool
    {
        return !in_array($value, ['0', 'false', 'no'], true);
    }

    /**
     * @return list<string>
     */
    private static function csv(string $value): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $value)), static fn(string $item): bool => $item !== ''));
    }
}
