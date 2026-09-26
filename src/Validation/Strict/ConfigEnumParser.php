<?php

declare(strict_types=1);

namespace Studio\Gesso\Validation\Strict;

use BackedEnum;
use InvalidArgumentException;

use function array_map;
use function implode;
use function sprintf;
use function strtolower;
use function trim;

/**
 * Shared parser behind the strict-mode enums' `fromConfigValue()`:
 * trim, lower-case, match a backed case, or throw the
 * "Unknown X value" error listing the accepted values.
 *
 * @internal Implementation detail of the strict-mode enums.
 */
final class ConfigEnumParser
{
    /** Static-only utility — no instances. */
    private function __construct() {}

    /**
     * @template T of BackedEnum
     *
     * @param class-string<T> $enum
     * @param array<string, string> $rejected normalised value => error message
     *                                        for values refused before case
     *                                        matching (e.g. per-call `fail`)
     *
     * @return null|T null when the value is null or blank (caller maps to Off)
     *
     * @throws InvalidArgumentException when a non-blank value matches no case
     */
    public static function parse(string $enum, string $parameter, ?string $value, array $rejected = []): ?BackedEnum
    {
        $normalized = strtolower(trim((string) $value));
        if ($normalized === '') {
            return null;
        }
        if (isset($rejected[$normalized])) {
            throw new InvalidArgumentException($rejected[$normalized]);
        }

        $match = $enum::tryFrom($normalized);
        if ($match !== null) {
            return $match;
        }

        $accepted = implode(', ', array_map(static fn(BackedEnum $c): string => (string) $c->value, $enum::cases()));

        throw new InvalidArgumentException(sprintf(
            "Unknown %s value '%s'. Accepted: %s.",
            $parameter,
            $value,
            $accepted,
        ));
    }
}
