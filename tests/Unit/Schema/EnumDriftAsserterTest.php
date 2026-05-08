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
use Studio\OpenApiContractTesting\Tests\Unit\Schema\Fixture\EmptySpecEnum;
use Studio\OpenApiContractTesting\Tests\Unit\Schema\Fixture\EnumKeyNotArrayEnum;
use Studio\OpenApiContractTesting\Tests\Unit\Schema\Fixture\IntegerBackedDriftEnum;
use Studio\OpenApiContractTesting\Tests\Unit\Schema\Fixture\IntegerBackedEnum;
use Studio\OpenApiContractTesting\Tests\Unit\Schema\Fixture\MalformedSpecEnum;
use Studio\OpenApiContractTesting\Tests\Unit\Schema\Fixture\MatchingEnum;
use Studio\OpenApiContractTesting\Tests\Unit\Schema\Fixture\NoEnumKeySpecEnum;
use Studio\OpenApiContractTesting\Tests\Unit\Schema\Fixture\NonScalarSpecValueEnum;
use Studio\OpenApiContractTesting\Tests\Unit\Schema\Fixture\NotAnEnum;
use Studio\OpenApiContractTesting\Tests\Unit\Schema\Fixture\PhpExtraEnum;
use Studio\OpenApiContractTesting\Tests\Unit\Schema\Fixture\PureEnum;
use Studio\OpenApiContractTesting\Tests\Unit\Schema\Fixture\SpecExtraEnum;
use Studio\OpenApiContractTesting\Tests\Unit\Schema\Fixture\SpecFileMissingEnum;
use Studio\OpenApiContractTesting\Tests\Unit\Schema\Fixture\UnattributedEnum;

use function restore_error_handler;
use function set_error_handler;
use function sys_get_temp_dir;
use function uniqid;

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
            // Reports preserve input order so consumers building dashboards
            // can correlate row N with the N-th class they passed in.
            $this->assertSame(PhpExtraEnum::class, $e->reports[0]->enumFqcn);
            $this->assertSame(SpecExtraEnum::class, $e->reports[1]->enumFqcn);
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

    #[Test]
    public function assert_no_drift_passes_for_integer_backed_enum(): void
    {
        EnumDriftAsserter::assertNoDrift([IntegerBackedEnum::class]);

        $reports = EnumDriftAsserter::detectAll([IntegerBackedEnum::class]);
        $this->assertFalse($reports[0]->hasDrift());
    }

    #[Test]
    public function strict_comparison_surfaces_string_vs_int_drift(): void
    {
        // Spec carries `[200, 201, "201"]` against a PHP enum of `200, 201`.
        // The string "201" must surface as spec-only drift even though
        // == comparison would equate it with the int 201.
        try {
            EnumDriftAsserter::assertNoDrift([IntegerBackedDriftEnum::class]);
            $this->fail('expected EnumDriftException');
        } catch (EnumDriftException $e) {
            $this->assertSame(['201'], $e->reports[0]->specOnly);
            $this->assertSame([], $e->reports[0]->phpOnly);
        }
    }

    #[Test]
    public function diagnostic_renders_int_values_unquoted(): void
    {
        try {
            EnumDriftAsserter::assertNoDrift([IntegerBackedDriftEnum::class]);
            $this->fail('expected EnumDriftException');
        } catch (EnumDriftException $e) {
            // String values are quoted in the diagnostic; ints are not.
            $this->assertStringContainsString('Spec-only (1): "201"', $e->getMessage());
        }
    }

    #[Test]
    public function pure_enum_throws_target_is_not_backed_enum(): void
    {
        try {
            EnumDriftAsserter::assertNoDrift([PureEnum::class]);
            $this->fail('expected EnumBindingException');
        } catch (EnumBindingException $e) {
            $this->assertSame(EnumBindingReason::TargetIsNotBackedEnum, $e->reason);
            $this->assertStringContainsString('pure enum', $e->getMessage());
        }
    }

    #[Test]
    public function non_scalar_spec_enum_value_throws_enum_value_unsupported(): void
    {
        try {
            EnumDriftAsserter::assertNoDrift([NonScalarSpecValueEnum::class]);
            $this->fail('expected EnumBindingException');
        } catch (EnumBindingException $e) {
            $this->assertSame(EnumBindingReason::EnumValueUnsupported, $e->reason);
            $this->assertStringContainsString('index 1', $e->getMessage());
            $this->assertStringContainsString('null', $e->getMessage());
        }
    }

    #[Test]
    public function empty_spec_enum_array_reports_all_php_cases_as_drift(): void
    {
        try {
            EnumDriftAsserter::assertNoDrift([EmptySpecEnum::class]);
            $this->fail('expected EnumDriftException');
        } catch (EnumDriftException $e) {
            $this->assertSame(['a', 'b'], $e->reports[0]->phpOnly);
            $this->assertSame([], $e->reports[0]->specOnly);
        }
    }

    #[Test]
    public function enum_base_path_resolves_attribute_paths_independently_of_spec_base_path(): void
    {
        // Issue #170: bundled-external layout. spec_base_path can point at
        // an unrelated bundle root while #[BoundToOpenApiEnum] paths still
        // resolve correctly under enum_spec_base_path.
        OpenApiSpecLoader::reset();
        OpenApiSpecLoader::configure(
            basePath: sys_get_temp_dir(),
            enumBasePath: self::SPEC_BASE_PATH,
        );

        EnumDriftAsserter::assertNoDrift([MatchingEnum::class]);

        $reports = EnumDriftAsserter::detectAll([MatchingEnum::class]);
        $this->assertCount(1, $reports);
        $this->assertFalse($reports[0]->hasDrift());
    }

    #[Test]
    public function enum_base_path_unset_falls_back_to_spec_base_path(): void
    {
        // Default setUp() configures only spec_base_path. Existing tests
        // already imply the fallback works; this test pins the contract
        // explicitly so a future loader refactor can't drift it silently.
        $this->assertNull(OpenApiSpecLoader::getEnumBasePath());

        EnumDriftAsserter::assertNoDrift([MatchingEnum::class]);
    }

    #[Test]
    public function enum_base_path_equal_to_spec_base_path_is_a_no_op(): void
    {
        // Compare report objects field-by-field between the fallback branch
        // (no enumBasePath) and the opt-in branch (enumBasePath = basePath).
        // A subtle bug where the opt-in branch produced a different
        // specPath / phpOnly / specOnly would slip through a "did it throw?"
        // shaped test — this one fails it.
        OpenApiSpecLoader::reset();
        OpenApiSpecLoader::configure(basePath: self::SPEC_BASE_PATH);
        $baseline = EnumDriftAsserter::detectAll([MatchingEnum::class, PhpExtraEnum::class]);

        OpenApiSpecLoader::reset();
        OpenApiSpecLoader::configure(
            basePath: self::SPEC_BASE_PATH,
            enumBasePath: self::SPEC_BASE_PATH,
        );
        $optIn = EnumDriftAsserter::detectAll([MatchingEnum::class, PhpExtraEnum::class]);

        $this->assertCount(2, $optIn);
        foreach ($optIn as $i => $report) {
            $this->assertSame($baseline[$i]->enumFqcn, $report->enumFqcn);
            $this->assertSame($baseline[$i]->specPath, $report->specPath);
            $this->assertSame($baseline[$i]->phpOnly, $report->phpOnly);
            $this->assertSame($baseline[$i]->specOnly, $report->specOnly);
            $this->assertSame($baseline[$i]->hasDrift(), $report->hasDrift());
        }
    }

    #[Test]
    public function enum_base_path_with_trailing_slash_resolves_identically(): void
    {
        // OpenApiSpecLoader::configure() trims trailing slashes (covered at
        // the loader unit level). End-to-end through the asserter pins that
        // the trim survives the full resolution path — a future refactor
        // that stops trimming and instead concatenates blindly would still
        // pass the loader-level test alone.
        OpenApiSpecLoader::reset();
        OpenApiSpecLoader::configure(
            basePath: self::SPEC_BASE_PATH,
            enumBasePath: self::SPEC_BASE_PATH . '/',
        );

        EnumDriftAsserter::assertNoDrift([MatchingEnum::class]);

        $reports = EnumDriftAsserter::detectAll([MatchingEnum::class]);
        $this->assertFalse($reports[0]->hasDrift());
        $this->assertSame('enum-drift/matching.json', $reports[0]->specPath);
    }

    #[Test]
    public function enum_base_path_set_but_spec_file_missing_throws_spec_file_not_found(): void
    {
        // enum_spec_base_path resolves to a real directory, but the
        // attribute path doesn't land on a file under it. Should surface
        // the existing SpecFileNotFound reason — not the new
        // EnumBasePathNotFound, which is reserved for the dir-itself-missing
        // case.
        $emptyDir = sys_get_temp_dir();
        OpenApiSpecLoader::reset();
        OpenApiSpecLoader::configure(
            basePath: self::SPEC_BASE_PATH,
            enumBasePath: $emptyDir,
        );

        try {
            EnumDriftAsserter::assertNoDrift([MatchingEnum::class]);
            $this->fail('expected EnumBindingException');
        } catch (EnumBindingException $e) {
            $this->assertSame(EnumBindingReason::SpecFileNotFound, $e->reason);
            $this->assertSame('enum-drift/matching.json', $e->specPath);
        }
    }

    #[Test]
    public function enum_base_path_pointing_at_nonexistent_dir_throws_enum_base_path_not_found(): void
    {
        $missing = sys_get_temp_dir() . '/openapi-contract-testing-missing-' . uniqid();
        OpenApiSpecLoader::reset();
        OpenApiSpecLoader::configure(
            basePath: self::SPEC_BASE_PATH,
            enumBasePath: $missing,
        );

        try {
            EnumDriftAsserter::assertNoDrift([MatchingEnum::class]);
            $this->fail('expected EnumBindingException');
        } catch (EnumBindingException $e) {
            $this->assertSame(EnumBindingReason::EnumBasePathNotFound, $e->reason);
            $this->assertStringContainsString($missing, $e->getMessage());
        }
    }
}
