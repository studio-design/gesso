<?php

declare(strict_types=1);

namespace Studio\Gesso\Validation\Strict;

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
        return ConfigEnumParser::parse(self::class, 'strict_additional_properties_per_call', $value, [
            'fail' => "strict_additional_properties_per_call does not support 'fail'. "
                . 'Use failOnWarning="true" for immediate failures, or '
                . 'strict_additional_properties=fail for the run-level gate.',
        ]) ?? self::Off;
    }
}
