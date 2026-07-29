<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\Gesso\ValidationOutput;
use Studio\Gesso\ValidationOutputFormat;

use function putenv;

class ValidationOutputTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        putenv('OPENAPI_VALIDATION_OUTPUT');
        ValidationOutput::reset();
    }

    protected function tearDown(): void
    {
        putenv('OPENAPI_VALIDATION_OUTPUT');
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
        putenv('OPENAPI_VALIDATION_OUTPUT=json');

        $this->assertSame(ValidationOutputFormat::Json, ValidationOutput::format());
    }

    #[Test]
    public function env_text_overrides_a_programmatic_json_selection(): void
    {
        ValidationOutput::use(ValidationOutputFormat::Json);
        putenv('OPENAPI_VALIDATION_OUTPUT=text');

        $this->assertSame(ValidationOutputFormat::Text, ValidationOutput::format());
    }

    #[Test]
    public function env_is_case_insensitive(): void
    {
        putenv('OPENAPI_VALIDATION_OUTPUT=JSON');

        $this->assertSame(ValidationOutputFormat::Json, ValidationOutput::format());
    }

    #[Test]
    public function env_trims_whitespace(): void
    {
        putenv('OPENAPI_VALIDATION_OUTPUT=  json  ');

        $this->assertSame(ValidationOutputFormat::Json, ValidationOutput::format());
    }

    #[Test]
    public function empty_env_falls_through_to_the_programmatic_selection(): void
    {
        ValidationOutput::use(ValidationOutputFormat::Json);
        putenv('OPENAPI_VALIDATION_OUTPUT=');

        $this->assertSame(ValidationOutputFormat::Json, ValidationOutput::format());
    }

    #[Test]
    public function whitespace_only_env_falls_through_to_the_programmatic_selection(): void
    {
        ValidationOutput::use(ValidationOutputFormat::Json);
        putenv('OPENAPI_VALIDATION_OUTPUT=  ');

        $this->assertSame(ValidationOutputFormat::Json, ValidationOutput::format());
    }

    #[Test]
    public function invalid_env_falls_through_to_the_programmatic_selection(): void
    {
        ValidationOutput::use(ValidationOutputFormat::Json);
        putenv('OPENAPI_VALIDATION_OUTPUT=yaml');

        $this->assertSame(ValidationOutputFormat::Json, ValidationOutput::format());
    }

    #[Test]
    public function invalid_env_without_a_programmatic_selection_falls_back_to_text(): void
    {
        putenv('OPENAPI_VALIDATION_OUTPUT=yaml');

        $this->assertSame(ValidationOutputFormat::Text, ValidationOutput::format());
    }
}
