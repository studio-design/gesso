<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Tests\Unit\Schema;

use const E_USER_WARNING;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\OpenApiContractTesting\Exception\EnumBindingException;
use Studio\OpenApiContractTesting\Exception\EnumBindingReason;
use Studio\OpenApiContractTesting\Exception\EnumDriftException;
use Studio\OpenApiContractTesting\Schema\EnumDriftAsserter;
use Studio\OpenApiContractTesting\Spec\OpenApiSpecLoader;
use Studio\OpenApiContractTesting\Tests\Unit\Schema\Fixture\EnumKeyNotArrayEnum;
use Studio\OpenApiContractTesting\Tests\Unit\Schema\Fixture\MalformedSpecEnum;
use Studio\OpenApiContractTesting\Tests\Unit\Schema\Fixture\MatchingEnum;
use Studio\OpenApiContractTesting\Tests\Unit\Schema\Fixture\NoEnumKeySpecEnum;
use Studio\OpenApiContractTesting\Tests\Unit\Schema\Fixture\NotAnEnum;
use Studio\OpenApiContractTesting\Tests\Unit\Schema\Fixture\PhpExtraEnum;
use Studio\OpenApiContractTesting\Tests\Unit\Schema\Fixture\SpecExtraEnum;
use Studio\OpenApiContractTesting\Tests\Unit\Schema\Fixture\SpecFileMissingEnum;
use Studio\OpenApiContractTesting\Tests\Unit\Schema\Fixture\UnattributedEnum;

use function restore_error_handler;
use function set_error_handler;

class EnumDriftAsserterTest extends TestCase
{
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
    public function assert_no_drift_passes_for_matching_pair(): void
    {
        EnumDriftAsserter::assertNoDrift([MatchingEnum::class]);

        // detectAll mirrors the same resolution path; verifying its report
        // confirms the matching path executed without throwing — a real
        // post-condition rather than `assertTrue(true)`.
        $reports = EnumDriftAsserter::detectAll([MatchingEnum::class]);
        $this->assertCount(1, $reports);
        $this->assertFalse($reports[0]->hasDrift());
    }

    #[Test]
    public function assert_no_drift_throws_when_php_has_extra_cases(): void
    {
        try {
            EnumDriftAsserter::assertNoDrift([PhpExtraEnum::class]);
            $this->fail('expected EnumDriftException');
        } catch (EnumDriftException $e) {
            $this->assertCount(1, $e->reports);
            $this->assertSame(['blue'], $e->reports[0]->phpOnly);
            $this->assertSame([], $e->reports[0]->specOnly);
            $this->assertStringContainsString('PHP-only', $e->getMessage());
            $this->assertStringContainsString('blue', $e->getMessage());
        }
    }

    #[Test]
    public function assert_no_drift_throws_when_spec_has_extra_values(): void
    {
        try {
            EnumDriftAsserter::assertNoDrift([SpecExtraEnum::class]);
            $this->fail('expected EnumDriftException');
        } catch (EnumDriftException $e) {
            $this->assertCount(1, $e->reports);
            $this->assertSame([], $e->reports[0]->phpOnly);
            $this->assertSame(['yellow'], $e->reports[0]->specOnly);
            $this->assertStringContainsString('Spec-only', $e->getMessage());
            $this->assertStringContainsString('yellow', $e->getMessage());
        }
    }

    #[Test]
    public function assert_no_drift_aggregates_multiple_drifting_enums(): void
    {
        try {
            EnumDriftAsserter::assertNoDrift([
                PhpExtraEnum::class,
                SpecExtraEnum::class,
            ]);
            $this->fail('expected EnumDriftException');
        } catch (EnumDriftException $e) {
            $this->assertCount(2, $e->reports);
            $this->assertStringContainsString('2 enum binding(s) drift', $e->getMessage());
        }
    }

    #[Test]
    public function assert_no_drift_skips_clean_enums_in_aggregated_report(): void
    {
        // Mixed input: one clean + one drifting. Only the drifting one
        // should appear on the exception, so the diagnostic isn't padded
        // with reports the user already knows are fine.
        try {
            EnumDriftAsserter::assertNoDrift([
                MatchingEnum::class,
                PhpExtraEnum::class,
            ]);
            $this->fail('expected EnumDriftException');
        } catch (EnumDriftException $e) {
            $this->assertCount(1, $e->reports);
            $this->assertSame(PhpExtraEnum::class, $e->reports[0]->enumFqcn);
        }
    }

    #[Test]
    public function detect_all_returns_clean_reports_too(): void
    {
        // detectAll() is the inspection seam — callers building UI / CI
        // dashboards want the full picture, not just drift.
        $reports = EnumDriftAsserter::detectAll([
            MatchingEnum::class,
            PhpExtraEnum::class,
        ]);

        $this->assertCount(2, $reports);
        $this->assertFalse($reports[0]->hasDrift());
        $this->assertTrue($reports[1]->hasDrift());
    }

    #[Test]
    public function assert_no_drift_throws_binding_exception_for_unattributed_enum(): void
    {
        try {
            EnumDriftAsserter::assertNoDrift([UnattributedEnum::class]);
            $this->fail('expected EnumBindingException');
        } catch (EnumBindingException $e) {
            $this->assertSame(EnumBindingReason::AttributeMissing, $e->reason);
            $this->assertSame(UnattributedEnum::class, $e->enumFqcn);
        }
    }

    #[Test]
    public function assert_no_drift_throws_binding_exception_when_target_is_not_enum(): void
    {
        try {
            EnumDriftAsserter::assertNoDrift([NotAnEnum::class]);
            $this->fail('expected EnumBindingException');
        } catch (EnumBindingException $e) {
            $this->assertSame(EnumBindingReason::TargetIsNotEnum, $e->reason);
            $this->assertSame(NotAnEnum::class, $e->enumFqcn);
        }
    }

    #[Test]
    public function assert_no_drift_throws_binding_exception_when_class_does_not_exist(): void
    {
        try {
            EnumDriftAsserter::assertNoDrift(['Studio\\NoSuch\\Enum']);
            $this->fail('expected EnumBindingException');
        } catch (EnumBindingException $e) {
            $this->assertSame(EnumBindingReason::TargetIsNotEnum, $e->reason);
        }
    }

    #[Test]
    public function assert_no_drift_throws_binding_exception_when_base_path_unconfigured(): void
    {
        OpenApiSpecLoader::reset();

        try {
            EnumDriftAsserter::assertNoDrift([MatchingEnum::class]);
            $this->fail('expected EnumBindingException');
        } catch (EnumBindingException $e) {
            $this->assertSame(EnumBindingReason::BasePathNotConfigured, $e->reason);
        }
    }

    #[Test]
    public function assert_no_drift_throws_binding_exception_when_spec_file_missing(): void
    {
        try {
            EnumDriftAsserter::assertNoDrift([SpecFileMissingEnum::class]);
            $this->fail('expected EnumBindingException');
        } catch (EnumBindingException $e) {
            $this->assertSame(EnumBindingReason::SpecFileNotFound, $e->reason);
            $this->assertSame('enum-drift/does-not-exist.json', $e->specPath);
        }
    }

    #[Test]
    public function assert_no_drift_throws_binding_exception_for_malformed_json(): void
    {
        try {
            EnumDriftAsserter::assertNoDrift([MalformedSpecEnum::class]);
            $this->fail('expected EnumBindingException');
        } catch (EnumBindingException $e) {
            $this->assertSame(EnumBindingReason::MalformedJson, $e->reason);
        }
    }

    #[Test]
    public function assert_no_drift_throws_binding_exception_when_enum_key_missing(): void
    {
        try {
            EnumDriftAsserter::assertNoDrift([NoEnumKeySpecEnum::class]);
            $this->fail('expected EnumBindingException');
        } catch (EnumBindingException $e) {
            $this->assertSame(EnumBindingReason::EnumKeyMissing, $e->reason);
        }
    }

    #[Test]
    public function assert_no_drift_throws_binding_exception_when_enum_key_not_array(): void
    {
        try {
            EnumDriftAsserter::assertNoDrift([EnumKeyNotArrayEnum::class]);
            $this->fail('expected EnumBindingException');
        } catch (EnumBindingException $e) {
            $this->assertSame(EnumBindingReason::EnumKeyNotArray, $e->reason);
        }
    }

    #[Test]
    public function fail_on_drift_false_emits_user_warning_instead_of_throwing(): void
    {
        $captured = [];
        set_error_handler(static function (int $errno, string $msg) use (&$captured): bool {
            $captured[] = ['errno' => $errno, 'msg' => $msg];

            return true;
        });

        try {
            EnumDriftAsserter::assertNoDrift([PhpExtraEnum::class], failOnDrift: false);
        } finally {
            restore_error_handler();
        }

        $this->assertCount(1, $captured);
        $this->assertSame(E_USER_WARNING, $captured[0]['errno']);
        $this->assertStringContainsString('PHP-only', $captured[0]['msg']);
    }

    #[Test]
    public function fail_on_drift_false_does_not_emit_warning_when_clean(): void
    {
        $captured = [];
        set_error_handler(static function (int $errno, string $msg) use (&$captured): bool {
            $captured[] = ['errno' => $errno, 'msg' => $msg];

            return true;
        });

        try {
            EnumDriftAsserter::assertNoDrift([MatchingEnum::class], failOnDrift: false);
        } finally {
            restore_error_handler();
        }

        $this->assertSame([], $captured);
    }

    #[Test]
    public function assert_no_drift_accepts_empty_list_as_no_op(): void
    {
        EnumDriftAsserter::assertNoDrift([]);

        $this->assertSame([], EnumDriftAsserter::detectAll([]));
    }

    #[Test]
    public function diagnostic_message_contains_structured_block(): void
    {
        try {
            EnumDriftAsserter::assertNoDrift([
                PhpExtraEnum::class,
                SpecExtraEnum::class,
            ]);
            $this->fail('expected EnumDriftException');
        } catch (EnumDriftException $e) {
            $msg = $e->getMessage();

            // Header
            $this->assertStringContainsString('[OpenAPI Enum Drift]', $msg);
            $this->assertStringContainsString('FATAL', $msg);
            $this->assertStringContainsString('2 enum binding(s) drift from spec', $msg);

            // Per-enum body
            $this->assertStringContainsString(PhpExtraEnum::class, $msg);
            $this->assertStringContainsString('enum-drift/php-extra.json', $msg);
            $this->assertStringContainsString(SpecExtraEnum::class, $msg);
            $this->assertStringContainsString('enum-drift/spec-extra.json', $msg);

            // Footer
            $this->assertStringContainsString('Action:', $msg);
        }
    }
}
