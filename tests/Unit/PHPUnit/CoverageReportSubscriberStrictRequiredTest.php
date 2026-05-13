<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Tests\Unit\PHPUnit;

use PHPUnit\Event\TestRunner\ExecutionFinished;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Studio\OpenApiContractTesting\Coverage\OpenApiCoverageTracker;
use Studio\OpenApiContractTesting\PHPUnit\ConsoleOutput;
use Studio\OpenApiContractTesting\PHPUnit\CoverageReportSubscriber;
use Studio\OpenApiContractTesting\Spec\OpenApiSpecLoader;
use Studio\OpenApiContractTesting\Validation\Strict\StrictRequiredMode;
use Studio\OpenApiContractTesting\Validation\Strict\StrictRequiredTracker;

use function getenv;
use function ob_get_clean;
use function ob_start;
use function putenv;

class CoverageReportSubscriberStrictRequiredTest extends TestCase
{
    private const SPEC_NAME = 'under-described';
    private ?string $previousTestToken = null;

    protected function setUp(): void
    {
        parent::setUp();
        OpenApiCoverageTracker::reset();
        StrictRequiredTracker::reset();
        OpenApiSpecLoader::reset();
        OpenApiSpecLoader::configure(__DIR__ . '/../../fixtures/specs');

        $current = getenv('TEST_TOKEN');
        $this->previousTestToken = $current === false ? null : $current;
        putenv('TEST_TOKEN');
    }

    protected function tearDown(): void
    {
        if ($this->previousTestToken === null) {
            putenv('TEST_TOKEN');
        } else {
            putenv('TEST_TOKEN=' . $this->previousTestToken);
        }
        StrictRequiredTracker::reset();
        OpenApiCoverageTracker::reset();
        OpenApiSpecLoader::reset();
        parent::tearDown();
    }

    #[Test]
    public function off_mode_does_not_invoke_asserter(): void
    {
        StrictRequiredTracker::record(self::SPEC_NAME, 'PUT', '/signed-url', '200', 'application/json', ['expires', 'signed_url', 'url']);

        $stderr = '';
        $subscriber = $this->makeSubscriber(
            mode: StrictRequiredMode::Off,
            stderr: $stderr,
            exitCode: $exitCode,
        );

        ob_start();
        $subscriber->notify($this->fakeExecutionFinished());
        ob_get_clean();

        $this->assertSame('', $stderr);
        $this->assertNull($exitCode);
    }

    #[Test]
    public function warn_mode_writes_diagnostic_but_does_not_exit(): void
    {
        StrictRequiredTracker::record(self::SPEC_NAME, 'PUT', '/signed-url', '200', 'application/json', ['expires', 'signed_url', 'url']);

        $stderr = '';
        $exitCode = null;
        $subscriber = $this->makeSubscriber(
            mode: StrictRequiredMode::Warn,
            stderr: $stderr,
            exitCode: $exitCode,
        );

        ob_start();
        $subscriber->notify($this->fakeExecutionFinished());
        ob_get_clean();

        $this->assertNull($exitCode, 'warn mode must not exit');
        $this->assertStringContainsString('[OpenAPI Strict Required] WARNING', $stderr);
        $this->assertStringContainsString('PUT /signed-url', $stderr);
        $this->assertStringContainsString('expires', $stderr);
    }

    #[Test]
    public function fail_mode_invokes_exit_handler_with_one(): void
    {
        StrictRequiredTracker::record(self::SPEC_NAME, 'PUT', '/signed-url', '200', 'application/json', ['expires', 'signed_url', 'url']);

        $stderr = '';
        $exitCode = null;
        $subscriber = $this->makeSubscriber(
            mode: StrictRequiredMode::Fail,
            stderr: $stderr,
            exitCode: $exitCode,
        );

        ob_start();
        $subscriber->notify($this->fakeExecutionFinished());
        ob_get_clean();

        $this->assertSame(1, $exitCode, 'fail mode must request exit(1)');
        $this->assertStringContainsString('[OpenAPI Strict Required] FATAL', $stderr);
    }

    #[Test]
    public function no_drift_in_warn_mode_emits_nothing(): void
    {
        StrictRequiredTracker::record(self::SPEC_NAME, 'GET', '/users/{id}', '200', 'application/json', ['id', 'name']);

        $stderr = '';
        $exitCode = null;
        $subscriber = $this->makeSubscriber(
            mode: StrictRequiredMode::Warn,
            stderr: $stderr,
            exitCode: $exitCode,
        );

        ob_start();
        $subscriber->notify($this->fakeExecutionFinished());
        ob_get_clean();

        $this->assertSame('', $stderr);
        $this->assertNull($exitCode);
    }

    #[Test]
    public function worker_mode_emits_note_when_strict_required_is_enabled(): void
    {
        putenv('TEST_TOKEN=2');

        $stderr = '';
        $subscriber = $this->makeSubscriber(
            mode: StrictRequiredMode::Warn,
            stderr: $stderr,
            exitCode: $exitCode,
        );

        ob_start();
        $subscriber->notify($this->fakeExecutionFinished());
        ob_get_clean();

        $this->assertStringContainsString('[OpenAPI Strict Required] NOTE', $stderr);
        $this->assertStringContainsString('sequential-only', $stderr);
    }

    /**
     * @param-out null|int $exitCode
     * @param-out string $stderr
     */
    private function makeSubscriber(
        StrictRequiredMode $mode,
        string &$stderr,
        ?int &$exitCode,
    ): CoverageReportSubscriber {
        $exitCode = null;

        return new CoverageReportSubscriber(
            specs: [self::SPEC_NAME],
            outputFile: null,
            consoleOutput: ConsoleOutput::DEFAULT,
            githubSummaryPath: null,
            stderrWriter: static function (string $msg) use (&$stderr): void {
                $stderr .= $msg;
            },
            sidecarDir: null,
            exitHandler: static function (int $code) use (&$exitCode): void {
                $exitCode = $code;
            },
            strictRequiredMode: $mode,
        );
    }

    private function fakeExecutionFinished(): ExecutionFinished
    {
        return (new ReflectionClass(ExecutionFinished::class))->newInstanceWithoutConstructor();
    }
}
