<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Tests\Unit\PHPUnit;

use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\OpenApiContractTesting\Exception\EnumBindingException;
use Studio\OpenApiContractTesting\Exception\EnumBindingReason;
use Studio\OpenApiContractTesting\PHPUnit\AssertsNoEnumDrift;
use Studio\OpenApiContractTesting\Spec\OpenApiSpecLoader;
use Studio\OpenApiContractTesting\Tests\Unit\Schema\Fixture\MatchingEnum;
use Studio\OpenApiContractTesting\Tests\Unit\Schema\Fixture\NotAnEnum;
use Studio\OpenApiContractTesting\Tests\Unit\Schema\Fixture\PhpExtraEnum;
use Studio\OpenApiContractTesting\Tests\Unit\Schema\Fixture\SpecExtraEnum;

use function array_column;
use function str_contains;
use function str_replace;

class AssertsNoEnumDriftTest extends TestCase
{
    use AssertsNoEnumDrift;

    private const SPEC_BASE_PATH = __DIR__ . '/../../fixtures/specs';

    protected function setUp(): void
    {
        parent::setUp();
        OpenApiSpecLoader::reset();
        OpenApiSpecLoader::configure(self::SPEC_BASE_PATH);
    }

    protected function tearDown(): void
    {
        OpenApiSpecLoader::reset();
        parent::tearDown();
    }

    #[Test]
    public function clean_enum_increments_assertion_count_by_one(): void
    {
        $before = $this->numberOfAssertionsPerformed();

        $this->assertNoEnumDrift([MatchingEnum::class]);

        // Exactly one assertion was credited — that is the whole point of
        // the trait. The follow-up assertSame() does NOT skew the delta
        // because we capture both anchor values before it executes.
        $after = $this->numberOfAssertionsPerformed();
        $this->assertSame($before + 1, $after);
    }

    #[Test]
    public function clean_input_with_multiple_enums_still_credits_one_assertion(): void
    {
        // The trait's contract is "one logical assertion per call",
        // not "one per enum checked". This pins that decision so a future
        // refactor cannot drift it without an explicit test change.
        $before = $this->numberOfAssertionsPerformed();

        $this->assertNoEnumDrift([MatchingEnum::class, MatchingEnum::class]);

        $after = $this->numberOfAssertionsPerformed();
        $this->assertSame($before + 1, $after);
    }

    #[Test]
    public function php_only_drift_fails_with_assertion_failed_error(): void
    {
        try {
            $this->assertNoEnumDrift([PhpExtraEnum::class]);
            $this->fail('expected AssertionFailedError');
        } catch (AssertionFailedError $e) {
            $message = $e->getMessage();
            $this->assertStringContainsString('[OpenAPI Enum Drift] FATAL', $message);
            $this->assertStringContainsString('1 enum binding(s) drift', $message);
            $this->assertStringContainsString('PHP-only', $message);
            $this->assertStringContainsString('blue', $message);
        }
    }

    #[Test]
    public function spec_only_drift_fails_with_assertion_failed_error(): void
    {
        try {
            $this->assertNoEnumDrift([SpecExtraEnum::class]);
            $this->fail('expected AssertionFailedError');
        } catch (AssertionFailedError $e) {
            $message = $e->getMessage();
            $this->assertStringContainsString('Spec-only', $message);
            $this->assertStringContainsString('yellow', $message);
        }
    }

    #[Test]
    public function mixed_input_reports_only_drifting_enums(): void
    {
        try {
            $this->assertNoEnumDrift([MatchingEnum::class, PhpExtraEnum::class]);
            $this->fail('expected AssertionFailedError');
        } catch (AssertionFailedError $e) {
            $message = $e->getMessage();
            // Only the drifting enum should appear in the rendered block —
            // clean enums are filtered out so the diagnostic stays signal-only.
            $this->assertStringContainsString('1 enum binding(s) drift', $message);
            $this->assertStringContainsString(PhpExtraEnum::class, $message);
            $this->assertStringNotContainsString(MatchingEnum::class, $message);
        }
    }

    #[Test]
    public function misconfigured_enum_bubbles_enum_binding_exception(): void
    {
        // Misconfiguration is a setup error, not a drift assertion failure.
        // The trait must NOT wrap this in an AssertionFailedError — letting
        // EnumBindingException propagate preserves the structured $reason +
        // $enumFqcn properties that downstream tooling relies on.
        try {
            $this->assertNoEnumDrift([NotAnEnum::class]);
            $this->fail('expected EnumBindingException');
        } catch (EnumBindingException $e) {
            $this->assertSame(EnumBindingReason::TargetIsNotEnum, $e->reason);
            $this->assertSame(NotAnEnum::class, $e->enumFqcn);
        }
    }

    #[Test]
    public function failure_trace_strips_library_frames(): void
    {
        // The trait routes failures through StackTraceFilter so consumers
        // see their own test line at the top of the trace rather than this
        // library's internals. Verify the trait's own file is not in the
        // filtered frames.
        try {
            $this->assertNoEnumDrift([PhpExtraEnum::class]);
            $this->fail('expected AssertionFailedError');
        } catch (AssertionFailedError $e) {
            $files = array_column($e->getTrace(), 'file');
            foreach ($files as $file) {
                $normalized = str_replace('\\', '/', $file);
                $this->assertFalse(
                    str_contains($normalized, '/src/PHPUnit/AssertsNoEnumDrift.php'),
                    'StackTraceFilter should drop frames inside the trait file, got: ' . $normalized,
                );
            }
        }
    }
}
