<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Tests\Unit\Validation\Strict;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\OpenApiContractTesting\Validation\Strict\StrictRequiredPerCallMode;

final class StrictRequiredPerCallModeTest extends TestCase
{
    #[Test]
    public function from_config_value_returns_off_when_value_is_null(): void
    {
        $this->assertSame(StrictRequiredPerCallMode::Off, StrictRequiredPerCallMode::fromConfigValue(null));
    }

    #[Test]
    public function from_config_value_returns_off_when_value_is_empty_string(): void
    {
        $this->assertSame(StrictRequiredPerCallMode::Off, StrictRequiredPerCallMode::fromConfigValue(''));
        $this->assertSame(StrictRequiredPerCallMode::Off, StrictRequiredPerCallMode::fromConfigValue('   '));
    }

    #[Test]
    public function from_config_value_parses_off(): void
    {
        $this->assertSame(StrictRequiredPerCallMode::Off, StrictRequiredPerCallMode::fromConfigValue('off'));
    }

    #[Test]
    public function from_config_value_parses_warn(): void
    {
        $this->assertSame(StrictRequiredPerCallMode::Warn, StrictRequiredPerCallMode::fromConfigValue('warn'));
    }

    #[Test]
    public function from_config_value_is_case_insensitive(): void
    {
        $this->assertSame(StrictRequiredPerCallMode::Warn, StrictRequiredPerCallMode::fromConfigValue('Warn'));
        $this->assertSame(StrictRequiredPerCallMode::Warn, StrictRequiredPerCallMode::fromConfigValue('WARN'));
    }

    #[Test]
    public function from_config_value_trims_whitespace(): void
    {
        $this->assertSame(StrictRequiredPerCallMode::Warn, StrictRequiredPerCallMode::fromConfigValue('  warn  '));
    }

    #[Test]
    public function from_config_value_rejects_fail_value(): void
    {
        // Per-call mode is intentionally warn-only — the run-level
        // {@see StrictRequiredMode} provides the safe fail-gate. Silently
        // demoting `fail` here would let a CI ship with a misconfigured
        // gate; reject loudly instead.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Unknown strict_required_per_call value 'fail'");
        StrictRequiredPerCallMode::fromConfigValue('fail');
    }

    #[Test]
    public function from_config_value_rejects_unknown_value(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Unknown strict_required_per_call value 'enforce'");
        StrictRequiredPerCallMode::fromConfigValue('enforce');
    }

    #[Test]
    public function is_enabled_reflects_non_off_modes(): void
    {
        $this->assertFalse(StrictRequiredPerCallMode::Off->isEnabled());
        $this->assertTrue(StrictRequiredPerCallMode::Warn->isEnabled());
    }
}
