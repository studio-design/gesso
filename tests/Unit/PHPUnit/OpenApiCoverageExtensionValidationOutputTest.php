<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit\PHPUnit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use PHPUnit\Runner\Extension\ParameterCollection;
use Studio\Gesso\PHPUnit\OpenApiCoverageExtension;
use Studio\Gesso\Spec\OpenApiSpecLoader;
use Studio\Gesso\ValidationOutput;
use Studio\Gesso\ValidationOutputFormat;

use function fclose;
use function fopen;
use function putenv;
use function rewind;
use function stream_get_contents;

class OpenApiCoverageExtensionValidationOutputTest extends TestCase
{
    /** @var null|resource */
    private $stderrBuffer;

    protected function setUp(): void
    {
        parent::setUp();
        OpenApiSpecLoader::reset();
        putenv('OPENAPI_VALIDATION_OUTPUT');
        ValidationOutput::reset();

        $buffer = fopen('php://memory', 'w+');
        if ($buffer === false) {
            $this->fail('Could not open in-memory buffer for STDERR capture');
        }
        $this->stderrBuffer = $buffer;
        OpenApiCoverageExtension::overrideStderrForTesting($buffer);
    }

    protected function tearDown(): void
    {
        OpenApiCoverageExtension::overrideStderrForTesting(null);
        if ($this->stderrBuffer !== null) {
            fclose($this->stderrBuffer);
            $this->stderrBuffer = null;
        }
        OpenApiSpecLoader::reset();
        putenv('OPENAPI_VALIDATION_OUTPUT');
        ValidationOutput::reset();
        parent::tearDown();
    }

    #[Test]
    public function validation_output_parameter_selects_the_json_format(): void
    {
        $this->setupExtension(['validation_output' => 'json']);

        $this->assertSame(ValidationOutputFormat::Json, ValidationOutput::format());
    }

    #[Test]
    public function validation_output_parameter_is_trimmed_and_case_insensitive(): void
    {
        $this->setupExtension(['validation_output' => '  JSON  ']);

        $this->assertSame(ValidationOutputFormat::Json, ValidationOutput::format());
    }

    #[Test]
    public function absent_parameter_keeps_the_text_default(): void
    {
        $this->setupExtension([]);

        $this->assertSame(ValidationOutputFormat::Text, ValidationOutput::format());
    }

    #[Test]
    public function invalid_parameter_warns_and_keeps_the_text_default(): void
    {
        $this->setupExtension(['validation_output' => 'yaml']);

        $this->assertSame(ValidationOutputFormat::Text, ValidationOutput::format());
        $this->assertStringContainsString(
            "Invalid validation_output parameter 'yaml'. Valid values: text, json.",
            $this->capturedStderr(),
        );
    }

    #[Test]
    public function environment_variable_still_wins_over_the_parameter(): void
    {
        putenv('OPENAPI_VALIDATION_OUTPUT=text');

        $this->setupExtension(['validation_output' => 'json']);

        $this->assertSame(ValidationOutputFormat::Text, ValidationOutput::format());
    }

    private function setupExtension(array $parameters): void
    {
        $extension = new OpenApiCoverageExtension();
        $extension->setupExtension(null, ParameterCollection::fromArray([
            'spec_base_path' => __DIR__ . '/../../fixtures/specs',
            'specs' => 'petstore-3.0',
            ...$parameters,
        ]), null);
    }

    private function capturedStderr(): string
    {
        if ($this->stderrBuffer === null) {
            return '';
        }
        rewind($this->stderrBuffer);

        return (string) stream_get_contents($this->stderrBuffer);
    }
}
