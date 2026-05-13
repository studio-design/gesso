<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Tests\Unit\Validation\Strict;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\OpenApiContractTesting\Validation\Strict\StrictRequiredMode;

class StrictRequiredModeTest extends TestCase
{
    #[Test]
    public function from_config_value_returns_off_when_value_is_null(): void
    {
        $this->assertSame(StrictRequiredMode::Off, StrictRequiredMode::fromConfigValue(null));
    }

    #[Test]
    public function from_config_value_returns_off_when_value_is_empty_string(): void
    {
        $this->assertSame(StrictRequiredMode::Off, StrictRequiredMode::fromConfigValue(''));
    }

    #[Test]
    public function from_config_value_parses_off(): void
    {
        $this->assertSame(StrictRequiredMode::Off, StrictRequiredMode::fromConfigValue('off'));
    }

    #[Test]
    public function from_config_value_parses_warn(): void
    {
        $this->assertSame(StrictRequiredMode::Warn, StrictRequiredMode::fromConfigValue('warn'));
    }

    #[Test]
    public function from_config_value_parses_fail(): void
    {
        $this->assertSame(StrictRequiredMode::Fail, StrictRequiredMode::fromConfigValue('fail'));
    }

    #[Test]
    public function from_config_value_is_case_insensitive(): void
    {
        $this->assertSame(StrictRequiredMode::Warn, StrictRequiredMode::fromConfigValue('WARN'));
        $this->assertSame(StrictRequiredMode::Fail, StrictRequiredMode::fromConfigValue('Fail'));
    }

    #[Test]
    public function from_config_value_trims_whitespace(): void
    {
        $this->assertSame(StrictRequiredMode::Warn, StrictRequiredMode::fromConfigValue('  warn  '));
    }

    #[Test]
    public function from_config_value_rejects_unknown_value(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Unknown strict_required value 'enforce'");

        StrictRequiredMode::fromConfigValue('enforce');
    }

    #[Test]
    public function is_enabled_reflects_non_off_modes(): void
    {
        $this->assertFalse(StrictRequiredMode::Off->isEnabled());
        $this->assertTrue(StrictRequiredMode::Warn->isEnabled());
        $this->assertTrue(StrictRequiredMode::Fail->isEnabled());
    }
}
