<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit\Schema;

use const E_USER_WARNING;

use ArgumentCountError;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\Gesso\Exception\EnumBindingException;
use Studio\Gesso\Exception\EnumBindingReason;
use Studio\Gesso\Exception\EnumDriftException;
use Studio\Gesso\Internal\YamlAvailability;
use Studio\Gesso\Schema\EnumDriftAsserter;
use Studio\Gesso\Spec\OpenApiSpecLoader;
use Studio\Gesso\Tests\Unit\PHPUnit\Fixture\EnumDrift\ByName\Color as DriftingColor;
use Studio\Gesso\Tests\Unit\Schema\Fixture\ArgumentlessAttributeEnum;
use Studio\Gesso\Tests\Unit\Schema\Fixture\Broken;
use Studio\Gesso\Tests\Unit\Schema\Fixture\Color;
use Studio\Gesso\Tests\Unit\Schema\Fixture\ComponentOverrideEnum;
use Studio\Gesso\Tests\Unit\Schema\Fixture\EmptyKeyed;
use Studio\Gesso\Tests\Unit\Schema\Fixture\EmptySpecEnum;
use Studio\Gesso\Tests\Unit\Schema\Fixture\Flags;
use Studio\Gesso\Tests\Unit\Schema\Fixture\IntegerBackedDriftEnum;
use Studio\Gesso\Tests\Unit\Schema\Fixture\IntegerBackedEnum;
use Studio\Gesso\Tests\Unit\Schema\Fixture\Keyed;
use Studio\Gesso\Tests\Unit\Schema\Fixture\MatchingEnum;
use Studio\Gesso\Tests\Unit\Schema\Fixture\MissingComponentEnum;
use Studio\Gesso\Tests\Unit\Schema\Fixture\NonScalarSpecValueEnum;
use Studio\Gesso\Tests\Unit\Schema\Fixture\NotAnEnum;
use Studio\Gesso\Tests\Unit\Schema\Fixture\NumericKeyed;
use Studio\Gesso\Tests\Unit\Schema\Fixture\PaymentStatus;
use Studio\Gesso\Tests\Unit\Schema\Fixture\PhpExtraEnum;
use Studio\Gesso\Tests\Unit\Schema\Fixture\PureEnum;
use Studio\Gesso\Tests\Unit\Schema\Fixture\RefKeyed;
use Studio\Gesso\Tests\Unit\Schema\Fixture\SharedCode;
use Studio\Gesso\Tests\Unit\Schema\Fixture\ShippingMethodEnum;
use Studio\Gesso\Tests\Unit\Schema\Fixture\SpecExtraEnum;
use Studio\Gesso\Tests\Unit\Schema\Fixture\UnattributedEnum;

use function file_put_contents;
use function mkdir;
use function restore_error_handler;
use function rmdir;
use function set_error_handler;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

class EnumDriftAsserterTest extends TestCase
{
    private const SPEC_BASE_PATH = __DIR__ . '/../../fixtures/specs';
    private const COMPONENT_SPECS = ['enum-drift/components'];
    private const BINDING_SPECS = ['enum-drift/bindings'];

    protected function setUp(): void
    {
        parent::setUp();
        OpenApiSpecLoader::reset();
        OpenApiSpecLoader::configure(self::SPEC_BASE_PATH);
    }

    protected function tearDown(): void
    {
        YamlAvailability::reset();
        OpenApiSpecLoader::reset();
        parent::tearDown();
    }

    /**
     * @return iterable<string, array{list<string>, string}>
     */
    public static function provideComponent_attribute_naming_an_unknown_component_throws_component_not_foundCases(): iterable
    {
        yield 'configured specs' => [self::COMPONENT_SPECS, 'enum-drift/components'];
        yield 'no specs' => [[], 'none configured'];
    }

    /**
     * @return iterable<string, array{class-string, string, list<string>}>
     */
    public static function provideMatched_component_with_a_non_list_enum_throws_enum_key_not_arrayCases(): iterable
    {
        yield 'scalar' => [Broken::class, 'Broken', self::COMPONENT_SPECS];
        yield 'object' => [Keyed::class, 'Keyed', self::COMPONENT_SPECS];
        // The loader collapses these objects into lists (["red"], []).
        yield 'numeric-keyed object' => [NumericKeyed::class, 'NumericKeyed', self::COMPONENT_SPECS];
        yield 'empty object' => [EmptyKeyed::class, 'EmptyKeyed', self::COMPONENT_SPECS];
        yield 'object behind a $ref' => [RefKeyed::class, 'RefKeyed', self::COMPONENT_SPECS];
        yield 'yaml numeric-keyed mapping' => [NumericKeyed::class, 'NumericKeyed', ['enum-drift/components-yaml']];
        yield 'yaml empty mapping' => [EmptyKeyed::class, 'EmptyKeyed', ['enum-drift/components-yaml']];
    }

    #[Test]
    public function assert_no_drift_passes_for_matching_pair(): void
    {
        EnumDriftAsserter::assertNoDrift([MatchingEnum::class], specNames: self::BINDING_SPECS);

        // detectAll mirrors the same resolution path; verifying its report
        // confirms the matching path executed without throwing — a real
        // post-condition rather than `assertTrue(true)`.
        $reports = EnumDriftAsserter::detectAll([MatchingEnum::class], specNames: self::BINDING_SPECS);
        $this->assertCount(1, $reports);
        $this->assertFalse($reports[0]->hasDrift());
    }

    #[Test]
    public function assert_no_drift_throws_when_php_has_extra_cases(): void
    {
        try {
            EnumDriftAsserter::assertNoDrift([PhpExtraEnum::class], specNames: self::BINDING_SPECS);
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
            EnumDriftAsserter::assertNoDrift([SpecExtraEnum::class], specNames: self::BINDING_SPECS);
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
            ], specNames: self::BINDING_SPECS);
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
            ], specNames: self::BINDING_SPECS);
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
        ], specNames: self::BINDING_SPECS);

        $this->assertCount(2, $reports);
        $this->assertFalse($reports[0]->hasDrift());
        $this->assertTrue($reports[1]->hasDrift());
    }

    #[Test]
    public function assert_no_drift_throws_binding_exception_for_unattributed_enum(): void
    {
        try {
            EnumDriftAsserter::assertNoDrift([UnattributedEnum::class], specNames: self::BINDING_SPECS);
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
            EnumDriftAsserter::assertNoDrift([NotAnEnum::class], specNames: self::BINDING_SPECS);
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
            EnumDriftAsserter::assertNoDrift(['Studio\\NoSuch\\Enum'], specNames: self::BINDING_SPECS);
            $this->fail('expected EnumBindingException');
        } catch (EnumBindingException $e) {
            $this->assertSame(EnumBindingReason::TargetIsNotEnum, $e->reason);
        }
    }

    #[Test]
    public function fail_on_drift_false_emits_user_warning_instead_of_throwing(): void
    {
        $captured = [];
        set_error_handler(static function (int $errno, string $msg) use (&$captured): bool {
            $captured[] = ['errno' => $errno, 'msg' => $msg];

            return true;
        }, E_USER_WARNING);

        try {
            EnumDriftAsserter::assertNoDrift([PhpExtraEnum::class], failOnDrift: false, specNames: self::BINDING_SPECS);
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
        }, E_USER_WARNING);

        try {
            EnumDriftAsserter::assertNoDrift([MatchingEnum::class], failOnDrift: false, specNames: self::BINDING_SPECS);
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
            ], specNames: self::BINDING_SPECS);
            $this->fail('expected EnumDriftException');
        } catch (EnumDriftException $e) {
            $msg = $e->getMessage();

            // Header
            $this->assertStringContainsString('[OpenAPI Enum Drift]', $msg);
            $this->assertStringContainsString('FATAL', $msg);
            $this->assertStringContainsString('2 enum binding(s) drift from spec', $msg);

            // Per-enum body
            $this->assertStringContainsString(PhpExtraEnum::class, $msg);
            $this->assertStringContainsString('#/components/schemas/PhpExtra', $msg);
            $this->assertStringContainsString(SpecExtraEnum::class, $msg);
            $this->assertStringContainsString('#/components/schemas/SpecExtra', $msg);

            // Footer
            $this->assertStringContainsString('Action:', $msg);
        }
    }

    #[Test]
    public function assert_no_drift_passes_for_integer_backed_enum(): void
    {
        EnumDriftAsserter::assertNoDrift([IntegerBackedEnum::class], specNames: self::BINDING_SPECS);

        $reports = EnumDriftAsserter::detectAll([IntegerBackedEnum::class], specNames: self::BINDING_SPECS);
        $this->assertFalse($reports[0]->hasDrift());
    }

    #[Test]
    public function strict_comparison_surfaces_string_vs_int_drift(): void
    {
        // Spec carries `[200, 201, "201"]` against a PHP enum of `200, 201`.
        // The string "201" must surface as spec-only drift even though
        // == comparison would equate it with the int 201.
        try {
            EnumDriftAsserter::assertNoDrift([IntegerBackedDriftEnum::class], specNames: self::BINDING_SPECS);
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
            EnumDriftAsserter::assertNoDrift([IntegerBackedDriftEnum::class], specNames: self::BINDING_SPECS);
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
            EnumDriftAsserter::assertNoDrift([PureEnum::class], specNames: self::BINDING_SPECS);
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
            EnumDriftAsserter::assertNoDrift([NonScalarSpecValueEnum::class], specNames: self::BINDING_SPECS);
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
            EnumDriftAsserter::assertNoDrift([EmptySpecEnum::class], specNames: self::BINDING_SPECS);
            $this->fail('expected EnumDriftException');
        } catch (EnumDriftException $e) {
            $this->assertSame(['a', 'b'], $e->reports[0]->phpOnly);
            $this->assertSame([], $e->reports[0]->specOnly);
        }
    }

    #[Test]
    public function detect_all_matches_a_backed_enum_by_its_short_name(): void
    {
        $reports = EnumDriftAsserter::detectAll([Color::class], self::COMPONENT_SPECS);

        $this->assertCount(1, $reports);
        $this->assertSame('#/components/schemas/Color', $reports[0]->specPath);
        $this->assertFalse($reports[0]->hasDrift());
    }

    #[Test]
    public function detect_all_matches_with_enum_suffix_in_either_direction(): void
    {
        $reports = EnumDriftAsserter::detectAll(
            [PaymentStatus::class, ShippingMethodEnum::class],
            self::COMPONENT_SPECS,
        );

        $this->assertSame('#/components/schemas/PaymentStatusEnum', $reports[0]->specPath);
        $this->assertSame('#/components/schemas/ShippingMethod', $reports[1]->specPath);
        $this->assertFalse($reports[0]->hasDrift());
        $this->assertFalse($reports[1]->hasDrift());
    }

    #[Test]
    public function component_attribute_overrides_name_matching(): void
    {
        $reports = EnumDriftAsserter::detectAll([ComponentOverrideEnum::class], self::COMPONENT_SPECS);

        $this->assertSame('#/components/schemas/Color', $reports[0]->specPath);
        $this->assertFalse($reports[0]->hasDrift());
    }

    /**
     * @param list<string> $specNames
     */
    #[Test]
    #[DataProvider('provideComponent_attribute_naming_an_unknown_component_throws_component_not_foundCases')]
    public function component_attribute_naming_an_unknown_component_throws_component_not_found(array $specNames, string $expectedSpecs): void
    {
        try {
            EnumDriftAsserter::detectAll([MissingComponentEnum::class], $specNames);
            $this->fail('expected EnumBindingException');
        } catch (EnumBindingException $e) {
            $this->assertSame(EnumBindingReason::ComponentNotFound, $e->reason);
            $this->assertSame(MissingComponentEnum::class, $e->enumFqcn);
            $this->assertSame('#/components/schemas/DoesNotExist', $e->specPath);
            $this->assertStringContainsString($expectedSpecs, $e->getMessage());
        }
    }

    #[Test]
    public function unbound_enum_throws_attribute_missing_unless_skipped(): void
    {
        try {
            EnumDriftAsserter::detectAll([UnattributedEnum::class], self::COMPONENT_SPECS);
            $this->fail('expected EnumBindingException');
        } catch (EnumBindingException $e) {
            $this->assertSame(EnumBindingReason::AttributeMissing, $e->reason);
        }

        $this->assertSame([], EnumDriftAsserter::detectAll([UnattributedEnum::class], self::COMPONENT_SPECS, skipUnbound: true));
    }

    #[Test]
    public function conflicting_component_definitions_fail_only_when_matched(): void
    {
        $specs = ['enum-drift/components', 'enum-drift/components-conflict'];

        // Color is defined in both specs with the same value set (in another order).
        $this->assertFalse(EnumDriftAsserter::detectAll([Color::class], $specs)[0]->hasDrift());

        try {
            EnumDriftAsserter::detectAll([SharedCode::class], $specs);
            $this->fail('expected EnumBindingException');
        } catch (EnumBindingException $e) {
            $this->assertSame(EnumBindingReason::ComponentConflict, $e->reason);
            $this->assertSame('#/components/schemas/SharedCode', $e->specPath);
            $this->assertStringContainsString('enum-drift/components, enum-drift/components-conflict', $e->getMessage());
        }
    }

    #[Test]
    public function json_spec_bindings_need_no_symfony_yaml(): void
    {
        YamlAvailability::overrideForTesting(false);

        $this->assertFalse(EnumDriftAsserter::detectAll([Color::class], self::COMPONENT_SPECS)[0]->hasDrift());
        $this->assertSame(['black'], EnumDriftAsserter::detectAll([DriftingColor::class], self::COMPONENT_SPECS)[0]->phpOnly);
    }

    #[Test]
    public function name_matched_drift_is_reported_in_both_directions(): void
    {
        $reports = EnumDriftAsserter::detectAll([DriftingColor::class], self::COMPONENT_SPECS);

        $this->assertSame(['black'], $reports[0]->phpOnly);
        $this->assertSame([], $reports[0]->specOnly);
    }

    #[Test]
    public function component_enum_with_non_scalar_values_throws_only_when_matched(): void
    {
        // components.schemas.Flags is a boolean enum; Color never touches it.
        $this->assertCount(1, EnumDriftAsserter::detectAll([Color::class], self::COMPONENT_SPECS));

        try {
            EnumDriftAsserter::detectAll([Flags::class], self::COMPONENT_SPECS);
            $this->fail('expected EnumBindingException');
        } catch (EnumBindingException $e) {
            $this->assertSame(EnumBindingReason::EnumValueUnsupported, $e->reason);
            $this->assertSame('#/components/schemas/Flags', $e->specPath);
            $this->assertStringContainsString('components.schemas.Flags', $e->getMessage());
        }
    }

    /**
     * @param class-string $fqcn
     * @param list<string> $specNames
     */
    #[Test]
    #[DataProvider('provideMatched_component_with_a_non_list_enum_throws_enum_key_not_arrayCases')]
    public function matched_component_with_a_non_list_enum_throws_enum_key_not_array(string $fqcn, string $component, array $specNames): void
    {
        try {
            EnumDriftAsserter::detectAll([$fqcn], $specNames, skipUnbound: true);
            $this->fail('expected EnumBindingException');
        } catch (EnumBindingException $e) {
            $this->assertSame(EnumBindingReason::EnumKeyNotArray, $e->reason);
            $this->assertSame('#/components/schemas/' . $component, $e->specPath);
        }
    }

    #[Test]
    public function object_enum_of_a_cached_spec_is_rejected_without_rereading_the_file(): void
    {
        $dir = sys_get_temp_dir() . '/gesso-enum-cached-' . uniqid('', true);
        mkdir($dir);
        file_put_contents(
            $dir . '/api.json',
            '{"openapi": "3.1.0", "info": {"title": "t", "version": "1"}, "paths": {},'
            . ' "components": {"schemas": {"NumericKeyed": {"type": "string", "enum": {"0": "red"}}}}}',
        );
        OpenApiSpecLoader::configure($dir);
        OpenApiSpecLoader::load('api');
        unlink($dir . '/api.json');
        rmdir($dir);

        try {
            EnumDriftAsserter::detectAll([NumericKeyed::class], ['api'], skipUnbound: true);
            $this->fail('expected EnumBindingException');
        } catch (EnumBindingException $e) {
            $this->assertSame(EnumBindingReason::EnumKeyNotArray, $e->reason);
        }
    }

    #[Test]
    public function invalid_attribute_arguments_surface_as_a_binding_exception(): void
    {
        try {
            EnumDriftAsserter::detectAll([ArgumentlessAttributeEnum::class], self::COMPONENT_SPECS);
            $this->fail('expected EnumBindingException');
        } catch (EnumBindingException $e) {
            $this->assertSame(EnumBindingReason::AttributeInvalid, $e->reason);
            $this->assertSame(ArgumentlessAttributeEnum::class, $e->enumFqcn);
            $this->assertInstanceOf(ArgumentCountError::class, $e->getPrevious());
        }
    }

    #[Test]
    public function assert_no_drift_passes_spec_names_through(): void
    {
        EnumDriftAsserter::assertNoDrift([Color::class], true, self::COMPONENT_SPECS);

        $this->expectException(EnumDriftException::class);
        EnumDriftAsserter::assertNoDrift([DriftingColor::class], true, self::COMPONENT_SPECS);
    }
}
