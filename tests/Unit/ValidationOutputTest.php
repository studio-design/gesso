<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\Gesso\Internal\LegacyIdentity;
use Studio\Gesso\ValidationOutput;
use Studio\Gesso\ValidationOutputFormat;

use function putenv;

class ValidationOutputTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        LegacyIdentity::resetEnvForTesting('GESSO_VALIDATION_FORMAT');
        ValidationOutput::reset();
    }

    protected function tearDown(): void
    {
        LegacyIdentity::resetEnvForTesting('GESSO_VALIDATION_FORMAT');
        ValidationOutput::reset();

        parent::tearDown();
    }

    #[Test]
    public function format_defaults_to_text(): void
    {
        $this->assertSame(ValidationOutputFormat::Text, ValidationOutput::format());
    }

    #[Test]
    public function format_returns_the_programmatically_selected_format(): void
    {
        ValidationOutput::use(ValidationOutputFormat::Json);

        $this->assertSame(ValidationOutputFormat::Json, ValidationOutput::format());
    }

    #[Test]
    public function reset_restores_the_text_default(): void
    {
        ValidationOutput::use(ValidationOutputFormat::Json);
        ValidationOutput::reset();

        $this->assertSame(ValidationOutputFormat::Text, ValidationOutput::format());
    }

    #[Test]
    public function env_overrides_the_programmatically_selected_format(): void
    {
        ValidationOutput::use(ValidationOutputFormat::Text);
        putenv('GESSO_VALIDATION_FORMAT=json');

        $this->assertSame(ValidationOutputFormat::Json, ValidationOutput::format());
    }

    #[Test]
    public function env_text_overrides_a_programmatic_json_selection(): void
    {
        ValidationOutput::use(ValidationOutputFormat::Json);
        putenv('GESSO_VALIDATION_FORMAT=text');

        $this->assertSame(ValidationOutputFormat::Text, ValidationOutput::format());
    }

    #[Test]
    public function env_is_case_insensitive(): void
    {
        putenv('GESSO_VALIDATION_FORMAT=JSON');

        $this->assertSame(ValidationOutputFormat::Json, ValidationOutput::format());
    }

    #[Test]
    public function env_trims_whitespace(): void
    {
        putenv('GESSO_VALIDATION_FORMAT=  json  ');

        $this->assertSame(ValidationOutputFormat::Json, ValidationOutput::format());
    }

    #[Test]
    public function empty_env_falls_through_to_the_programmatic_selection(): void
    {
        ValidationOutput::use(ValidationOutputFormat::Json);
        putenv('GESSO_VALIDATION_FORMAT=');

        $this->assertSame(ValidationOutputFormat::Json, ValidationOutput::format());
    }

    #[Test]
    public function whitespace_only_env_falls_through_to_the_programmatic_selection(): void
    {
        ValidationOutput::use(ValidationOutputFormat::Json);
        putenv('GESSO_VALIDATION_FORMAT=  ');

        $this->assertSame(ValidationOutputFormat::Json, ValidationOutput::format());
    }

    #[Test]
    public function invalid_env_falls_through_to_the_programmatic_selection(): void
    {
        ValidationOutput::use(ValidationOutputFormat::Json);
        putenv('GESSO_VALIDATION_FORMAT=yaml');

        $this->assertSame(ValidationOutputFormat::Json, ValidationOutput::format());
    }

    #[Test]
    public function invalid_env_without_a_programmatic_selection_falls_back_to_text(): void
    {
        putenv('GESSO_VALIDATION_FORMAT=yaml');

        $this->assertSame(ValidationOutputFormat::Text, ValidationOutput::format());
    }

    /** Issue #504: this read site goes through {@see LegacyIdentity}, not bare getenv(). */
    #[Test]
    public function the_legacy_env_name_still_selects_the_format(): void
    {
        ValidationOutput::use(ValidationOutputFormat::Text);
        putenv('OPENAPI_VALIDATION_OUTPUT=json');

        $this->assertSame(ValidationOutputFormat::Json, ValidationOutput::format());
        $this->assertSame(
            ['[Gesso] WARNING: OPENAPI_VALIDATION_OUTPUT is deprecated and will be removed in Gesso '
                . LegacyIdentity::REMOVED_IN . '. Use GESSO_VALIDATION_FORMAT.'],
            LegacyIdentity::warnings(),
        );
    }

    #[Test]
    public function the_current_env_name_beats_the_legacy_one(): void
    {
        putenv('OPENAPI_VALIDATION_OUTPUT=text');
        putenv('GESSO_VALIDATION_FORMAT=json');

        $this->assertSame(ValidationOutputFormat::Json, ValidationOutput::format());
        $this->assertSame([], LegacyIdentity::warnings());
    }
}
