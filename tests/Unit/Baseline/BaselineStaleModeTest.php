<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit\Baseline;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\Gesso\Baseline\BaselineStaleMode;

class BaselineStaleModeTest extends TestCase
{
    #[Test]
    public function missing_and_empty_values_default_to_note(): void
    {
        $this->assertSame(BaselineStaleMode::Note, BaselineStaleMode::fromConfigValue(null));
        $this->assertSame(BaselineStaleMode::Note, BaselineStaleMode::fromConfigValue(''));
        $this->assertSame(BaselineStaleMode::Note, BaselineStaleMode::fromConfigValue('  '));
    }

    #[Test]
    public function values_parse_case_insensitively_with_whitespace_trimmed(): void
    {
        $this->assertSame(BaselineStaleMode::Off, BaselineStaleMode::fromConfigValue('off'));
        $this->assertSame(BaselineStaleMode::Note, BaselineStaleMode::fromConfigValue('Note'));
        $this->assertSame(BaselineStaleMode::Fail, BaselineStaleMode::fromConfigValue(' FAIL '));
    }

    #[Test]
    public function an_unknown_value_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Unknown baseline_stale value 'warn'. Accepted: off, note, fail.");

        BaselineStaleMode::fromConfigValue('warn');
    }
}
