<?php

declare(strict_types=1);

namespace Studio\Gesso\Validation\Strict;

use InvalidArgumentException;

use function array_map;
use function implode;
use function sprintf;
use function strtolower;
use function trim;

/**
 * Operating mode for undocumented response-property detection.
 *
 * @internal Configured by the PHPUnit extension and merge CLI.
 */
enum StrictAdditionalPropertiesMode: string
{
    case Off = 'off';
    case Warn = 'warn';
    case Fail = 'fail';

    public static function fromConfigValue(?string $value): self
    {
        if ($value === null) {
            return self::Off;
        }

        $normalized = strtolower(trim($value));
        if ($normalized === '') {
            return self::Off;
        }

        $match = self::tryFrom($normalized);
        if ($match !== null) {
            return $match;
        }

        $accepted = implode(', ', array_map(static fn(self $case): string => $case->value, self::cases()));

        throw new InvalidArgumentException(sprintf(
            "Unknown strict_additional_properties value '%s'. Accepted: %s.",
            $value,
            $accepted,
        ));
    }
}
