<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit\PHPUnit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use PHPUnit\Runner\Extension\ParameterCollection;
use Studio\Gesso\Baseline\InvalidBaselineConfigurationException;
use Studio\Gesso\Baseline\ViolationBaseline;
use Studio\Gesso\Baseline\ViolationBaselineCollector;
use Studio\Gesso\Baseline\ViolationBaselineEnforcer;
use Studio\Gesso\Baseline\ViolationBaselineFile;
use Studio\Gesso\Baseline\ViolationFingerprint;
use Studio\Gesso\PHPUnit\OpenApiCoverageExtension;
use Studio\Gesso\Spec\OpenApiSpecLoader;

use function fclose;
use function file_put_contents;
use function fopen;
use function getenv;
use function putenv;
use function rewind;
use function stream_get_contents;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

class OpenApiCoverageExtensionBaselineTest extends TestCase
{
    /** @var null|resource */
    private $stderrBuffer;
    private ?string $previousTestToken = null;
    private ?string $baselineFilePath = null;

    protected function setUp(): void
    {
        parent::setUp();
        OpenApiSpecLoader::reset();
        putenv('OPENAPI_BASELINE_GENERATE');
        ViolationBaselineCollector::resetCurrent();
        ViolationBaselineEnforcer::resetCurrent();

        $currentToken = getenv('TEST_TOKEN');
        $this->previousTestToken = $currentToken === false ? null : $currentToken;
        putenv('TEST_TOKEN');

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
        ViolationBaselineEnforcer::resetCurrent();
        if ($this->baselineFilePath !== null) {
            @unlink($this->baselineFilePath);
            $this->baselineFilePath = null;
        }
        if ($this->previousTestToken === null) {
            putenv('TEST_TOKEN');
        } else {
            putenv('TEST_TOKEN=' . $this->previousTestToken);
        }
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
        // Since PR2 a baseline_file without the generation env is an
        // enforcement run, which requires the file to exist.
        $this->setupExtension(['baseline_file' => $this->writeBaselineFixture()]);

        $this->assertNull(ViolationBaselineCollector::current());
    }

    #[Test]
    public function a_falsy_generation_env_value_installs_no_collector(): void
    {
        putenv('OPENAPI_BASELINE_GENERATE=0');

        $this->setupExtension(['baseline_file' => $this->writeBaselineFixture()]);

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
    public function generation_under_a_parallel_worker_installs_the_collector(): void
    {
        // Issue #417: paratest workers bootstrap generation exactly like a
        // sequential run — the subscriber's worker branch stages the
        // collected fingerprints in the sidecar envelope for
        // `gesso coverage:merge --baseline-file` instead of writing a file.
        putenv('OPENAPI_BASELINE_GENERATE=1');
        putenv('TEST_TOKEN=3');

        try {
            $this->setupExtension(['baseline_file' => 'gesso-baseline.json']);
            $this->assertNotNull(ViolationBaselineCollector::current());
        } finally {
            putenv('TEST_TOKEN');
        }
    }

    #[Test]
    public function a_stale_collector_from_a_previous_bootstrap_is_dropped(): void
    {
        ViolationBaselineCollector::setCurrent(new ViolationBaselineCollector());

        $this->setupExtension([]);

        $this->assertNull(ViolationBaselineCollector::current());
    }

    #[Test]
    public function a_configured_baseline_file_installs_the_enforcer(): void
    {
        $path = $this->writeBaselineFixture();

        $this->setupExtension(['baseline_file' => $path]);

        $enforcer = ViolationBaselineEnforcer::current();
        $this->assertNotNull($enforcer);
        $this->assertSame(1, $enforcer->baseline()->count());
        $this->assertNull(ViolationBaselineCollector::current());
    }

    #[Test]
    public function a_missing_baseline_file_is_fatal(): void
    {
        try {
            $this->setupExtension(['baseline_file' => sys_get_temp_dir() . '/gesso-nonexistent-' . uniqid() . '.json']);
            $this->fail('Expected an InvalidBaselineConfigurationException.');
        } catch (InvalidBaselineConfigurationException) {
            $this->assertStringContainsString('[Gesso] FATAL', $this->capturedStderr());
            $this->assertStringContainsString('baseline_file', $this->capturedStderr());
            $this->assertStringContainsString('OPENAPI_BASELINE_GENERATE', $this->capturedStderr());
            $this->assertNull(ViolationBaselineEnforcer::current());
        }
    }

    #[Test]
    public function a_malformed_baseline_file_is_fatal(): void
    {
        $path = sys_get_temp_dir() . '/gesso-baseline-' . uniqid() . '.json';
        file_put_contents($path, '{invalid');
        $this->baselineFilePath = $path;

        try {
            $this->setupExtension(['baseline_file' => $path]);
            $this->fail('Expected an InvalidBaselineConfigurationException.');
        } catch (InvalidBaselineConfigurationException) {
            $this->assertStringContainsString('[Gesso] FATAL', $this->capturedStderr());
            $this->assertNull(ViolationBaselineEnforcer::current());
        }
    }

    #[Test]
    public function a_generation_run_never_installs_the_enforcer(): void
    {
        putenv('OPENAPI_BASELINE_GENERATE=1');
        $path = $this->writeBaselineFixture();

        $this->setupExtension(['baseline_file' => $path]);

        $this->assertNotNull(ViolationBaselineCollector::current());
        $this->assertNull(ViolationBaselineEnforcer::current());
    }

    #[Test]
    public function a_stale_enforcer_from_a_previous_bootstrap_is_dropped(): void
    {
        ViolationBaselineEnforcer::setCurrent(new ViolationBaselineEnforcer(new ViolationBaseline()));

        $this->setupExtension([]);

        $this->assertNull(ViolationBaselineEnforcer::current());
    }

    #[Test]
    public function an_unknown_baseline_stale_value_is_fatal(): void
    {
        $path = $this->writeBaselineFixture();

        try {
            $this->setupExtension(['baseline_file' => $path, 'baseline_stale' => 'warn']);
            $this->fail('Expected an InvalidBaselineConfigurationException.');
        } catch (InvalidBaselineConfigurationException) {
            $this->assertStringContainsString('[Gesso] FATAL', $this->capturedStderr());
            $this->assertStringContainsString('baseline_stale', $this->capturedStderr());
            $this->assertNull(ViolationBaselineEnforcer::current());
        }
    }

    #[Test]
    public function baseline_stale_without_a_baseline_file_is_fatal(): void
    {
        try {
            $this->setupExtension(['baseline_stale' => 'note']);
            $this->fail('Expected an InvalidBaselineConfigurationException.');
        } catch (InvalidBaselineConfigurationException) {
            $this->assertStringContainsString('[Gesso] FATAL', $this->capturedStderr());
            $this->assertStringContainsString('baseline_stale', $this->capturedStderr());
            $this->assertStringContainsString('baseline_file', $this->capturedStderr());
        }
    }

    private function writeBaselineFixture(): string
    {
        $baseline = new ViolationBaseline();
        $baseline->add(new ViolationFingerprint('front', 'GET', '/v1/pets', '200', 'application/json', 'response.body', '/data/*/id', 'type'));
        $path = sys_get_temp_dir() . '/gesso-baseline-' . uniqid() . '.json';
        ViolationBaselineFile::write($path, $baseline);
        $this->baselineFilePath = $path;

        return $path;
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
