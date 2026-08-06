<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit\PHPUnit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use PHPUnit\Runner\Extension\ParameterCollection;
use Studio\Gesso\Baseline\CoverageBaseline;
use Studio\Gesso\Baseline\CoverageBaselineEntry;
use Studio\Gesso\Baseline\CoverageBaselineFile;
use Studio\Gesso\Baseline\InvalidBaselineConfigurationException;
use Studio\Gesso\Internal\LegacyIdentity;
use Studio\Gesso\PHPUnit\OpenApiCoverageExtension;
use Studio\Gesso\Spec\OpenApiSpecLoader;

use function fclose;
use function file_put_contents;
use function fopen;
use function putenv;
use function rewind;
use function stream_get_contents;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

/**
 * Issue #481: bootstrap-level handling of `coverage_baseline_file` /
 * `coverage_baseline_stale`.
 */
class OpenApiCoverageExtensionCoverageBaselineTest extends TestCase
{
    /** @var null|resource */
    private $stderrBuffer;

    /** @var list<string> */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        parent::setUp();
        OpenApiSpecLoader::reset();
        putenv('GESSO_BASELINE_GENERATE');
        putenv('OPENAPI_BASELINE_GENERATE');
        LegacyIdentity::resetForTesting();

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
        foreach ($this->tempFiles as $path) {
            @unlink($path);
        }
        $this->tempFiles = [];
        OpenApiSpecLoader::reset();
        putenv('GESSO_BASELINE_GENERATE');
        putenv('OPENAPI_BASELINE_GENERATE');
        LegacyIdentity::resetForTesting();
        parent::tearDown();
    }

    #[Test]
    public function a_readable_coverage_baseline_file_bootstraps_cleanly(): void
    {
        $this->setupExtension(['coverage_baseline_file' => $this->writeFixture()]);

        $this->assertSame('', $this->capturedStderr());
    }

    #[Test]
    public function a_missing_coverage_baseline_file_is_fatal(): void
    {
        try {
            $this->setupExtension(['coverage_baseline_file' => sys_get_temp_dir() . '/gesso-missing-' . uniqid() . '.json']);
            $this->fail('Expected an InvalidBaselineConfigurationException.');
        } catch (InvalidBaselineConfigurationException) {
            $this->assertStringContainsString('[Gesso] FATAL', $this->capturedStderr());
            $this->assertStringContainsString('coverage_baseline_file could not be loaded', $this->capturedStderr());
        }
    }

    #[Test]
    public function a_malformed_coverage_baseline_file_is_fatal(): void
    {
        $path = sys_get_temp_dir() . '/gesso-coverage-baseline-' . uniqid() . '.json';
        file_put_contents($path, '{"coverage_baseline_version": 99}');
        $this->tempFiles[] = $path;

        try {
            $this->setupExtension(['coverage_baseline_file' => $path]);
            $this->fail('Expected an InvalidBaselineConfigurationException.');
        } catch (InvalidBaselineConfigurationException) {
            $this->assertStringContainsString('Unsupported coverage_baseline_version', $this->capturedStderr());
        }
    }

    #[Test]
    public function a_generation_run_never_reads_the_coverage_baseline_file(): void
    {
        // The file is about to be overwritten; a missing or stale one must
        // not abort the very run that would fix it.
        putenv('GESSO_BASELINE_GENERATE=1');

        $this->setupExtension([
            'coverage_baseline_file' => sys_get_temp_dir() . '/gesso-missing-' . uniqid() . '.json',
        ]);

        $this->assertSame('', $this->capturedStderr());
    }

    #[Test]
    public function generation_with_only_a_coverage_baseline_file_is_allowed(): void
    {
        // Issue #481: `GESSO_BASELINE_GENERATE` drives both baselines, so
        // configuring only the coverage half must not trip the violation
        // baseline's "nowhere to write" guard.
        putenv('GESSO_BASELINE_GENERATE=1');

        $this->setupExtension(['coverage_baseline_file' => 'gesso-coverage-baseline.json']);

        $this->assertSame('', $this->capturedStderr());
    }

    #[Test]
    public function generation_without_either_baseline_file_is_fatal(): void
    {
        putenv('GESSO_BASELINE_GENERATE=1');

        try {
            $this->setupExtension([]);
            $this->fail('Expected an InvalidBaselineConfigurationException.');
        } catch (InvalidBaselineConfigurationException) {
            $this->assertStringContainsString('coverage_baseline_file', $this->capturedStderr());
        }
    }

    #[Test]
    public function an_unknown_coverage_baseline_stale_value_is_fatal(): void
    {
        try {
            $this->setupExtension([
                'coverage_baseline_file' => $this->writeFixture(),
                'coverage_baseline_stale' => 'warn',
            ]);
            $this->fail('Expected an InvalidBaselineConfigurationException.');
        } catch (InvalidBaselineConfigurationException) {
            $this->assertStringContainsString('Unknown baseline_stale value', $this->capturedStderr());
        }
    }

    #[Test]
    public function coverage_baseline_stale_without_its_file_is_fatal(): void
    {
        try {
            $this->setupExtension(['coverage_baseline_stale' => 'note']);
            $this->fail('Expected an InvalidBaselineConfigurationException.');
        } catch (InvalidBaselineConfigurationException) {
            $stderr = $this->capturedStderr();
            $this->assertStringContainsString('coverage_baseline_stale is set', $stderr);
            $this->assertStringContainsString('`coverage_baseline_file`', $stderr);
        }
    }

    #[Test]
    public function baseline_stale_still_points_at_the_violation_baseline_file(): void
    {
        // The two stale parameters are resolved by one helper; the error
        // message must keep naming the right partner parameter.
        try {
            $this->setupExtension(['baseline_stale' => 'note']);
            $this->fail('Expected an InvalidBaselineConfigurationException.');
        } catch (InvalidBaselineConfigurationException) {
            $stderr = $this->capturedStderr();
            $this->assertStringContainsString('baseline_stale is set', $stderr);
            $this->assertStringContainsString('`baseline_file`', $stderr);
            $this->assertStringNotContainsString('coverage_baseline_file', $stderr);
        }
    }

    private function writeFixture(): string
    {
        $baseline = new CoverageBaseline();
        $baseline->add(new CoverageBaselineEntry('petstore-3.0', 'GET', '/v1/pets', '500', 'application/json'));
        $path = sys_get_temp_dir() . '/gesso-coverage-baseline-' . uniqid() . '.json';
        CoverageBaselineFile::write($path, $baseline);
        $this->tempFiles[] = $path;

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
