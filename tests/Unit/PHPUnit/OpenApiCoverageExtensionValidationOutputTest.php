<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit\PHPUnit;

use PHPUnit\Framework\Attributes\IgnoreDeprecations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\Gesso\Internal\Deprecations;
use Studio\Gesso\Internal\LegacyIdentity;
use Studio\Gesso\PHPUnit\OpenApiCoverageExtension;
use Studio\Gesso\Spec\OpenApiSpecLoader;
use Studio\Gesso\Tests\Helpers\FlatExtensionSettings;
use Studio\Gesso\ValidationOutput;
use Studio\Gesso\ValidationOutputFormat;

use function fclose;
use function fopen;
use function putenv;

#[IgnoreDeprecations]
class OpenApiCoverageExtensionValidationOutputTest extends TestCase
{
    /** @var null|resource */
    private $stderrBuffer;

    protected function setUp(): void
    {
        parent::setUp();
        OpenApiSpecLoader::reset();
        LegacyIdentity::resetEnvForTesting('GESSO_VALIDATION_FORMAT');
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
        Deprecations::resetForTesting();
        OpenApiCoverageExtension::overrideStderrForTesting(null);
        if ($this->stderrBuffer !== null) {
            fclose($this->stderrBuffer);
            $this->stderrBuffer = null;
        }
        OpenApiSpecLoader::reset();
        LegacyIdentity::resetEnvForTesting('GESSO_VALIDATION_FORMAT');
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
    public function environment_variable_still_wins_over_the_parameter(): void
    {
        putenv('GESSO_VALIDATION_FORMAT=text');

        $this->setupExtension(['validation_output' => 'json']);

        $this->assertSame(ValidationOutputFormat::Text, ValidationOutput::format());
    }

    /** @param array<string, string> $parameters */
    private function setupExtension(array $parameters): void
    {
        $extension = new OpenApiCoverageExtension();
        $extension->setupExtension(null, FlatExtensionSettings::parameters([
            'spec_base_path' => __DIR__ . '/../../fixtures/specs',
            'specs' => 'petstore-3.0',
            ...$parameters,
        ]), null);
    }
}
