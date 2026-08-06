<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit\PHPUnit;

use const E_USER_DEPRECATED;

use PHPUnit\Event\TestRunner\ExecutionFinished;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Studio\Gesso\Coverage\OpenApiCoverageTracker;
use Studio\Gesso\Internal\Deprecations;
use Studio\Gesso\PHPUnit\ConsoleOutput;
use Studio\Gesso\PHPUnit\CoverageReportSubscriber;
use Studio\Gesso\Spec\OpenApiSpecLoader;

use function ob_get_clean;
use function ob_start;
use function restore_error_handler;
use function set_error_handler;
use function trigger_error;

final class CoverageReportSubscriberDeprecationsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        OpenApiCoverageTracker::reset();
        OpenApiSpecLoader::reset();
        OpenApiSpecLoader::configure(__DIR__ . '/../../fixtures/specs');
        Deprecations::resetForTesting();
        set_error_handler(static fn(int $errno): bool => $errno === E_USER_DEPRECATED);
    }

    protected function tearDown(): void
    {
        restore_error_handler();
        Deprecations::resetForTesting();
        OpenApiCoverageTracker::reset();
        OpenApiSpecLoader::reset();
        parent::tearDown();
    }

    #[Test]
    public function a_run_without_deprecations_writes_nothing(): void
    {
        $stderr = '';

        $this->notify($this->subscriber($stderr));

        $this->assertSame('', $stderr);
    }

    #[Test]
    public function a_run_with_deprecations_writes_exactly_one_summary_line(): void
    {
        Deprecations::notice(
            id: 'laravel.config.auto_inject_dummy_bearer',
            subject: "The Laravel config key 'auto_inject_dummy_bearer'",
            replacement: "'auto_inject_dummy_credentials'",
            removedIn: '3.0',
        );

        $stderr = '';

        $this->notify($this->subscriber($stderr));

        $this->assertSame(
            '[Gesso deprecation] 1 deprecated surface(s) still in use, 1 call(s):'
            . " laravel.config.auto_inject_dummy_bearer (1). All are removed in Gesso 3.0.\n",
            $stderr,
        );
    }

    #[Test]
    public function an_unrelated_e_user_deprecated_is_not_counted(): void
    {
        // The `#[SkipOpenApi]` advisory in ValidatesOpenApiSchema already
        // occupies PHPUnit's deprecation tally with a non-deprecation message.
        // The residual count reports Gesso's own channel only.
        trigger_error('[Gesso] SkipOpenApi advisory', E_USER_DEPRECATED);

        $stderr = '';

        $this->notify($this->subscriber($stderr));

        $this->assertSame('', $stderr);
    }

    private function subscriber(string &$stderr): CoverageReportSubscriber
    {
        return new CoverageReportSubscriber(
            specs: ['petstore-3.0'],
            outputFile: null,
            consoleOutput: ConsoleOutput::DEFAULT,
            githubSummaryPath: null,
            stderrWriter: static function (string $msg) use (&$stderr): void {
                $stderr .= $msg;
            },
        );
    }

    private function notify(CoverageReportSubscriber $subscriber): void
    {
        ob_start();
        $subscriber->notify(
            (new ReflectionClass(ExecutionFinished::class))->newInstanceWithoutConstructor(),
        );
        ob_get_clean();
    }
}
