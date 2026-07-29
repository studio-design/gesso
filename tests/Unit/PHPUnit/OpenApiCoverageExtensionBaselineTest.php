<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit\PHPUnit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use PHPUnit\Runner\Extension\ParameterCollection;
use Studio\Gesso\Baseline\InvalidBaselineConfigurationException;
use Studio\Gesso\Baseline\ViolationBaselineCollector;
use Studio\Gesso\PHPUnit\OpenApiCoverageExtension;
use Studio\Gesso\Spec\OpenApiSpecLoader;

use function fclose;
use function fopen;
use function putenv;
use function rewind;
use function stream_get_contents;

class OpenApiCoverageExtensionBaselineTest extends TestCase
{
    /** @var null|resource */
    private $stderrBuffer;

    protected function setUp(): void
    {
        parent::setUp();
        OpenApiSpecLoader::reset();
        putenv('OPENAPI_BASELINE_GENERATE');
        ViolationBaselineCollector::resetCurrent();

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
        putenv('OPENAPI_BASELINE_GENERATE');
        ViolationBaselineCollector::resetCurrent();
        parent::tearDown();
    }

    #[Test]
    public function generation_env_with_a_baseline_file_installs_the_collector(): void
    {
        putenv('OPENAPI_BASELINE_GENERATE=1');

        $this->setupExtension(['baseline_file' => 'gesso-baseline.json']);

        $this->assertNotNull(ViolationBaselineCollector::current());
    }

    #[Test]
    public function no_generation_env_installs_no_collector(): void
    {
        $this->setupExtension(['baseline_file' => 'gesso-baseline.json']);

        $this->assertNull(ViolationBaselineCollector::current());
    }

    #[Test]
    public function a_falsy_generation_env_value_installs_no_collector(): void
    {
        putenv('OPENAPI_BASELINE_GENERATE=0');

        $this->setupExtension(['baseline_file' => 'gesso-baseline.json']);

        $this->assertNull(ViolationBaselineCollector::current());
    }

    #[Test]
    public function generation_env_without_a_baseline_file_is_fatal(): void
    {
        putenv('OPENAPI_BASELINE_GENERATE=1');

        try {
            $this->setupExtension([]);
            $this->fail('Expected an InvalidBaselineConfigurationException.');
        } catch (InvalidBaselineConfigurationException) {
            $this->assertStringContainsString('[Gesso] FATAL', $this->capturedStderr());
            $this->assertStringContainsString('baseline_file', $this->capturedStderr());
            $this->assertNull(ViolationBaselineCollector::current());
        }
    }

    #[Test]
    public function a_stale_collector_from_a_previous_bootstrap_is_dropped(): void
    {
        ViolationBaselineCollector::setCurrent(new ViolationBaselineCollector());

        $this->setupExtension([]);

        $this->assertNull(ViolationBaselineCollector::current());
    }

    /** @param array<string, string> $parameters */
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
