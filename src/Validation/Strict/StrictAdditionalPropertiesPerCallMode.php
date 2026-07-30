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
 * Operating mode for immediate undocumented response-property warnings.
 *
 * @internal Configured by the PHPUnit extension.
 */
enum StrictAdditionalPropertiesPerCallMode: string
{
    case Off = 'off';
    case Warn = 'warn';

    public static function fromConfigValue(?string $value): self
    {
        if ($value === null) {
            return self::Off;
        }

        $normalized = strtolower(trim($value));
        if ($normalized === '') {
            return self::Off;
        }
        if ($normalized === 'fail') {
            throw new InvalidArgumentException(
                "strict_additional_properties_per_call does not support 'fail'. "
                . 'Use failOnWarning="true" for immediate failures, or '
                . 'strict_additional_properties=fail for the run-level gate.',
            );
        }

        $match = self::tryFrom($normalized);
        if ($match !== null) {
            return $match;
        }

        $accepted = implode(', ', array_map(static fn(self $case): string => $case->value, self::cases()));

        throw new InvalidArgumentException(sprintf(
            "Unknown strict_additional_properties_per_call value '%s'. Accepted: %s.",
            $value,
            $accepted,
        ));
    }
}
