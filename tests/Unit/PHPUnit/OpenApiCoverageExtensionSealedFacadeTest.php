<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit\PHPUnit;

use PHPUnit\Event\EventFacadeIsSealedException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use PHPUnit\Runner\Extension\ExtensionFacade;
use PHPUnit\Runner\Extension\Facade;
use PHPUnit\Runner\Extension\ParameterCollection;
use ReflectionClass;
use Studio\Gesso\Baseline\ViolationBaseline;
use Studio\Gesso\Baseline\ViolationBaselineEnforcer;
use Studio\Gesso\Baseline\ViolationBaselineFile;
use Studio\Gesso\Baseline\ViolationFingerprint;
use Studio\Gesso\PHPUnit\OpenApiCoverageExtension;
use Studio\Gesso\Spec\OpenApiSpecLoader;

use function class_exists;
use function fclose;
use function fopen;
use function interface_exists;
use function realpath;
use function rewind;
use function stream_get_contents;
use function substr_count;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

/**
 * Issue #598: Pest's parallel runner seals PHPUnit's event facade before
 * paratest's SuiteLoader bootstraps extensions in the orchestrator. The test
 * process's own facade is already sealed, so a real runner facade reproduces
 * that state without a stub.
 */
final class OpenApiCoverageExtensionSealedFacadeTest extends TestCase
{
    private const SUITE_LOADER = 'ParaTest\WrapperRunner\SuiteLoader';

    /** @var null|resource */
    private $stderrBuffer;

    /** @var mixed */
    private $previousArgv;
    private ?string $baselineFilePath = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousArgv = $_SERVER['argv'] ?? null;

        $stub = __DIR__ . '/../../fixtures/paratest/SuiteLoader.php';
        if (!class_exists(self::SUITE_LOADER)) {
            require_once $stub;
        }
        if ((new ReflectionClass(self::SUITE_LOADER))->getFileName() !== realpath($stub)) {
            $this->markTestSkipped('Real paratest is installed; the SuiteLoader stand-in cannot be declared.');
        }

        OpenApiSpecLoader::reset();
        ViolationBaselineEnforcer::resetCurrent();

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
        $_SERVER['argv'] = $this->previousArgv;
        OpenApiSpecLoader::reset();
        ViolationBaselineEnforcer::resetCurrent();
        if ($this->baselineFilePath !== null) {
            @unlink($this->baselineFilePath);
            $this->baselineFilePath = null;
        }
        parent::tearDown();
    }

    /** @return iterable<string, array{string}> */
    public static function providePest_parallel_orchestrator_notes_and_skips_registrationCases(): iterable
    {
        yield 'long flag' => ['--parallel'];
        yield 'short flag' => ['-p'];
        yield 'short flag with attached process count' => ['-p2'];
        yield 'short flag with equals value' => ['-p=4'];
        yield 'long flag with equals value' => ['--parallel=1'];
    }

    #[Test]
    #[DataProvider('providePest_parallel_orchestrator_notes_and_skips_registrationCases')]
    public function pest_parallel_orchestrator_notes_and_skips_registration(string $flag): void
    {
        $_SERVER['argv'] = ['vendor/bin/pest', $flag];

        $this->bootstrapThroughSuiteLoader([]);

        $stderr = $this->stderr();
        $this->assertSame(1, substr_count($stderr, '[Gesso] NOTE:'));
        $this->assertStringContainsString('Pest --parallel orchestrator', $stderr);
    }

    #[Test]
    public function pest_parallel_orchestrator_also_skips_the_baseline_completion_tracer(): void
    {
        $_SERVER['argv'] = ['vendor/bin/pest', '--parallel'];

        $this->bootstrapThroughSuiteLoader(['baseline_file' => $this->writeBaselineFixture()]);

        $this->assertNotNull(ViolationBaselineEnforcer::current());
        $this->assertSame(1, substr_count($this->stderr(), '[Gesso] NOTE:'));
    }

    #[Test]
    public function sealed_facade_outside_suite_loader_rethrows_even_with_the_parallel_flag(): void
    {
        // Pest's sequential fallback (`--parallel --retry`) keeps the flag
        // in argv but never bootstraps through paratest's SuiteLoader.
        $_SERVER['argv'] = ['vendor/bin/pest', '--parallel', '--retry'];

        try {
            (new OpenApiCoverageExtension())->setupExtension(self::sealedRunnerFacade(), $this->parameters([]), null);
            $this->fail('expected EventFacadeIsSealedException');
        } catch (EventFacadeIsSealedException) {
        }

        $this->assertStringNotContainsString('[Gesso] NOTE:', $this->stderr());
    }

    #[Test]
    public function sealed_facade_in_suite_loader_without_the_parallel_flag_rethrows(): void
    {
        $_SERVER['argv'] = ['vendor/bin/paratest'];

        $this->expectException(EventFacadeIsSealedException::class);

        $this->bootstrapThroughSuiteLoader([]);
    }

    /**
     * PHPUnit 12.x/13.0 ship `Facade` as a final class; 13.1+ as an interface
     * implemented by `ExtensionFacade`. Both delegate to the event facade.
     */
    private static function sealedRunnerFacade(): Facade
    {
        $class = interface_exists(Facade::class) ? ExtensionFacade::class : Facade::class;

        return new $class();
    }

    /** @param array<string, string> $parameters */
    private function bootstrapThroughSuiteLoader(array $parameters): void
    {
        $class = self::SUITE_LOADER;
        new $class(fn() => (new OpenApiCoverageExtension())->setupExtension(
            self::sealedRunnerFacade(),
            $this->parameters($parameters),
            null,
        ));
    }

    /**
     * @param array<string, string> $parameters
     */
    private function parameters(array $parameters): ParameterCollection
    {
        return ParameterCollection::fromArray([
            'spec_base_path' => __DIR__ . '/../../fixtures/specs',
            'specs' => 'petstore-3.0',
            ...$parameters,
        ]);
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

    private function stderr(): string
    {
        if ($this->stderrBuffer === null) {
            return '';
        }
        rewind($this->stderrBuffer);

        return (string) stream_get_contents($this->stderrBuffer);
    }
}
