<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit\PHPUnit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\Gesso\Internal\LegacyIdentity;
use Studio\Gesso\PHPUnit\ConsoleOutput;

use function putenv;

class ConsoleOutputTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Clear both spellings before each test
        putenv('GESSO_CONSOLE_OUTPUT');
        putenv('OPENAPI_CONSOLE_OUTPUT');
        LegacyIdentity::resetForTesting();
    }

    protected function tearDown(): void
    {
        putenv('GESSO_CONSOLE_OUTPUT');
        putenv('OPENAPI_CONSOLE_OUTPUT');
        LegacyIdentity::resetForTesting();

        parent::tearDown();
    }

    #[Test]
    public function resolve_returns_default_when_parameter_is_null(): void
    {
        $this->assertSame(ConsoleOutput::DEFAULT, ConsoleOutput::resolve(null));
    }

    #[Test]
    public function resolve_returns_default_when_parameter_is_empty_string(): void
    {
        $this->assertSame(ConsoleOutput::DEFAULT, ConsoleOutput::resolve(''));
    }

    #[Test]
    public function resolve_returns_default_when_parameter_is_whitespace(): void
    {
        $this->assertSame(ConsoleOutput::DEFAULT, ConsoleOutput::resolve('  '));
    }

    #[Test]
    public function resolve_returns_all_from_parameter(): void
    {
        $this->assertSame(ConsoleOutput::ALL, ConsoleOutput::resolve('all'));
    }

    #[Test]
    public function resolve_returns_uncovered_only_from_parameter(): void
    {
        $this->assertSame(ConsoleOutput::UNCOVERED_ONLY, ConsoleOutput::resolve('uncovered_only'));
    }

    #[Test]
    public function resolve_returns_active_only_from_parameter(): void
    {
        $this->assertSame(ConsoleOutput::ACTIVE_ONLY, ConsoleOutput::resolve('active_only'));
    }

    #[Test]
    public function resolve_returns_default_from_parameter(): void
    {
        $this->assertSame(ConsoleOutput::DEFAULT, ConsoleOutput::resolve('default'));
    }

    #[Test]
    public function resolve_is_case_insensitive_for_parameter(): void
    {
        $this->assertSame(ConsoleOutput::ALL, ConsoleOutput::resolve('ALL'));
        $this->assertSame(ConsoleOutput::ALL, ConsoleOutput::resolve('All'));
        $this->assertSame(ConsoleOutput::UNCOVERED_ONLY, ConsoleOutput::resolve('UNCOVERED_ONLY'));
        $this->assertSame(ConsoleOutput::UNCOVERED_ONLY, ConsoleOutput::resolve('Uncovered_Only'));
        $this->assertSame(ConsoleOutput::ACTIVE_ONLY, ConsoleOutput::resolve('ACTIVE_ONLY'));
        $this->assertSame(ConsoleOutput::ACTIVE_ONLY, ConsoleOutput::resolve('Active_Only'));
    }

    #[Test]
    public function resolve_trims_whitespace_from_parameter(): void
    {
        $this->assertSame(ConsoleOutput::ALL, ConsoleOutput::resolve('  all  '));
    }

    #[Test]
    public function resolve_returns_default_for_invalid_parameter(): void
    {
        $this->assertSame(ConsoleOutput::DEFAULT, ConsoleOutput::resolve('invalid'));
        $this->assertSame(ConsoleOutput::DEFAULT, ConsoleOutput::resolve('covered_only'));
    }

    #[Test]
    public function resolve_env_overrides_parameter(): void
    {
        putenv('GESSO_CONSOLE_OUTPUT=uncovered_only');

        $this->assertSame(ConsoleOutput::UNCOVERED_ONLY, ConsoleOutput::resolve('all'));
    }

    #[Test]
    public function resolve_env_is_case_insensitive(): void
    {
        putenv('GESSO_CONSOLE_OUTPUT=ALL');

        $this->assertSame(ConsoleOutput::ALL, ConsoleOutput::resolve(null));
    }

    #[Test]
    public function resolve_returns_active_only_from_env(): void
    {
        putenv('GESSO_CONSOLE_OUTPUT=active_only');

        $this->assertSame(ConsoleOutput::ACTIVE_ONLY, ConsoleOutput::resolve(null));
    }

    #[Test]
    public function resolve_env_trims_whitespace(): void
    {
        putenv('GESSO_CONSOLE_OUTPUT=  all  ');

        $this->assertSame(ConsoleOutput::ALL, ConsoleOutput::resolve(null));
    }

    #[Test]
    public function resolve_invalid_env_falls_back_to_default(): void
    {
        putenv('GESSO_CONSOLE_OUTPUT=invalid');

        $this->assertSame(ConsoleOutput::DEFAULT, ConsoleOutput::resolve('all'));
    }

    #[Test]
    public function resolve_empty_env_uses_parameter(): void
    {
        putenv('GESSO_CONSOLE_OUTPUT=');

        $this->assertSame(ConsoleOutput::ALL, ConsoleOutput::resolve('all'));
    }

    #[Test]
    public function resolve_whitespace_only_env_uses_parameter(): void
    {
        putenv('GESSO_CONSOLE_OUTPUT=  ');

        $this->assertSame(ConsoleOutput::ALL, ConsoleOutput::resolve('all'));
    }

    /** Issue #504: this read site goes through {@see LegacyIdentity}, not bare getenv(). */
    #[Test]
    public function resolve_still_honours_the_legacy_env_name(): void
    {
        putenv('OPENAPI_CONSOLE_OUTPUT=uncovered_only');

        $this->assertSame(ConsoleOutput::UNCOVERED_ONLY, ConsoleOutput::resolve('all'));
        $this->assertSame(
            ['[Gesso] WARNING: OPENAPI_CONSOLE_OUTPUT is deprecated and will be removed in Gesso '
                . LegacyIdentity::REMOVED_IN . '. Use GESSO_CONSOLE_OUTPUT.'],
            LegacyIdentity::warnings(),
        );
    }

    #[Test]
    public function resolve_prefers_the_current_env_name_over_the_legacy_one(): void
    {
        putenv('OPENAPI_CONSOLE_OUTPUT=uncovered_only');
        putenv('GESSO_CONSOLE_OUTPUT=active_only');

        $this->assertSame(ConsoleOutput::ACTIVE_ONLY, ConsoleOutput::resolve(null));
        $this->assertSame([], LegacyIdentity::warnings());
    }
}
