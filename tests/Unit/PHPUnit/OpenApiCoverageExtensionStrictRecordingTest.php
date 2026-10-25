<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit\PHPUnit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\IgnoreDeprecations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use PHPUnit\Runner\Extension\ParameterCollection;
use Studio\Gesso\Coverage\OpenApiCoverageTracker;
use Studio\Gesso\Coverage\SdkExerciseCoverageTracker;
use Studio\Gesso\Internal\Deprecations;
use Studio\Gesso\PHPUnit\OpenApiCoverageExtension;
use Studio\Gesso\Spec\OpenApiSpecLoader;
use Studio\Gesso\Validation\Strict\StrictAdditionalPropertiesPerCallChecker;
use Studio\Gesso\Validation\Strict\StrictAdditionalPropertiesTracker;
use Studio\Gesso\Validation\Strict\StrictRequiredPerCallChecker;
use Studio\Gesso\Validation\Strict\StrictRequiredTracker;

use function fclose;
use function fopen;
use function getenv;
use function putenv;

/**
 * The extension stops the strict trackers only when nothing can read them:
 * both modes off and no sidecar export for `gesso coverage:merge`.
 */
#[IgnoreDeprecations]
final class OpenApiCoverageExtensionStrictRecordingTest extends TestCase
{
    private const ENV = ['TEST_TOKEN', 'GESSO_SIDECAR_TOKEN'];

    /** @var array<string, false|string> */
    private array $previousEnv = [];

    /** @var null|resource */
    private $stderr;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (self::ENV as $name) {
            $this->previousEnv[$name] = getenv($name);
            putenv($name);
        }
        $stderr = fopen('php://memory', 'w+');
        $this->assertNotFalse($stderr);
        $this->stderr = $stderr;
        OpenApiCoverageExtension::overrideStderrForTesting($stderr);
        $this->resetState();
    }

    protected function tearDown(): void
    {
        $this->resetState();
        OpenApiCoverageExtension::overrideStderrForTesting(null);
        if ($this->stderr !== null) {
            fclose($this->stderr);
        }
        foreach ($this->previousEnv as $name => $value) {
            putenv($value === false ? $name : $name . '=' . $value);
        }
        parent::tearDown();
    }

    /**
     * @return iterable<string, array{array<string, string>, null|string, bool, bool}>
     */
    public static function provideBootstrap_sets_strict_recordingCases(): iterable
    {
        yield 'all off, sequential' => [[], null, false, false];
        yield 'run-level strict_required' => [['strict_required' => 'warn'], null, true, false];
        yield 'per-call strict_required' => [['strict_required_per_call' => 'warn'], null, true, false];
        yield 'run-level strict_additional_properties' => [['strict_additional_properties' => 'fail'], null, false, true];
        yield 'per-call strict_additional_properties' => [['strict_additional_properties_per_call' => 'warn'], null, false, true];
        yield 'paratest worker' => [[], 'TEST_TOKEN=3', true, true];
        yield 'CI shard' => [[], 'GESSO_SIDECAR_TOKEN=Feature', true, true];
    }

    /**
     * @param array<string, string> $parameters
     */
    #[Test]
    #[DataProvider('provideBootstrap_sets_strict_recordingCases')]
    public function bootstrap_sets_strict_recording(
        array $parameters,
        ?string $env,
        bool $requiredRecording,
        bool $additionalRecording,
    ): void {
        if ($env !== null) {
            putenv($env);
        }

        (new OpenApiCoverageExtension())->setupExtension(null, ParameterCollection::fromArray([
            'spec_base_path' => __DIR__ . '/../../fixtures/specs',
            'specs' => 'refs-valid',
            ...$parameters,
        ]), null);

        $this->assertSame($requiredRecording, StrictRequiredTracker::current()->isRecording());
        $this->assertSame($additionalRecording, StrictAdditionalPropertiesTracker::current()->isRecording());
    }

    private function resetState(): void
    {
        Deprecations::resetForTesting();
        OpenApiSpecLoader::reset();
        OpenApiCoverageTracker::resetCurrent();
        SdkExerciseCoverageTracker::resetCurrent();
        StrictRequiredTracker::resetCurrent();
        StrictAdditionalPropertiesTracker::resetCurrent();
        StrictRequiredPerCallChecker::reset();
        StrictAdditionalPropertiesPerCallChecker::reset();
    }
}
