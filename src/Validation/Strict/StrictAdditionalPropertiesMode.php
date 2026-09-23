<?php

declare(strict_types=1);

namespace Studio\Gesso\Validation\Strict;

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
        return ConfigEnumParser::parse(self::class, 'strict_additional_properties', $value) ?? self::Off;
    }
}
