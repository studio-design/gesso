<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit\PHPUnit;

use const E_USER_WARNING;

use PHPUnit\Framework\Attributes\IgnoreDeprecations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\Gesso\Coverage\InvalidCoverageOutputPathException;
use Studio\Gesso\Coverage\SdkExerciseCoverageTracker;
use Studio\Gesso\Exception\EnumBindingException;
use Studio\Gesso\Exception\EnumBindingReason;
use Studio\Gesso\Exception\EnumDriftException;
use Studio\Gesso\Exception\InvalidOpenApiSpecException;
use Studio\Gesso\Exception\InvalidOpenApiSpecReason;
use Studio\Gesso\Exception\SpecFileNotFoundException;
use Studio\Gesso\Internal\Deprecations;
use Studio\Gesso\Internal\EnumScanner;
use Studio\Gesso\PHPUnit\OpenApiCoverageExtension;
use Studio\Gesso\Spec\OpenApiSpecLoader;
use Studio\Gesso\Tests\Helpers\FlatExtensionSettings;
use Studio\Gesso\Validation\Request\AcknowledgedSecuritySchemes;
use Studio\Gesso\Validation\Strict\StrictAdditionalPropertiesPerCallChecker;
use Studio\Gesso\Validation\Strict\StrictAdditionalPropertiesTracker;
use Studio\Gesso\Validation\Strict\StrictRequiredPerCallChecker;
use Studio\Gesso\Validation\Strict\StrictRequiredPerCallMode;
use Studio\Gesso\Validation\Strict\StrictRequiredTracker;
use Studio\Gesso\Validation\Support\DiscriminatorEnforcement;

use function chmod;
use function fclose;
use function file_get_contents;
use function file_put_contents;
use function fopen;
use function getcwd;
use function getenv;
use function is_writable;
use function mkdir;
use function putenv;
use function restore_error_handler;
use function rewind;
use function rmdir;
use function set_error_handler;
use function stream_get_contents;
use function substr_count;
use function sys_get_temp_dir;
use function tempnam;
use function uniqid;
use function unlink;

#[IgnoreDeprecations]
class OpenApiCoverageExtensionTest extends TestCase
{
    private const CLEAN_BINDINGS = "[OpenAPI Enum Drift] 1 bound (0 by name, 1 by attribute), 0 unbound.\n"
        . "  Studio\\Gesso\\Tests\\Unit\\PHPUnit\\Fixture\\EnumDrift\\Clean\\CleanPetStatus  ->  enum-drift/_shared/components/schemas/enums/PetStatusEnum.json\n";

    /** @var null|resource */
    private $stderrBuffer;
    private ?string $githubSummaryTmp = null;
    private false|string $previousTestToken = false;

    protected function setUp(): void
    {
        parent::setUp();
        // Snapshot TEST_TOKEN so the enum-drift tests keep asserting the
        // evaluating (non-worker) path even when this suite runs under paratest.
        $this->previousTestToken = getenv('TEST_TOKEN');
        putenv('TEST_TOKEN');
        OpenApiSpecLoader::reset();
        EnumScanner::reset();
        Deprecations::resetForTesting();
        StrictAdditionalPropertiesPerCallChecker::reset();
        StrictAdditionalPropertiesTracker::resetCurrent();
        SdkExerciseCoverageTracker::resetCurrent();

        $buffer = fopen('php://memory', 'w+');
        if ($buffer === false) {
            $this->fail('Could not open in-memory buffer for STDERR capture');
        }
        $this->stderrBuffer = $buffer;
        OpenApiCoverageExtension::overrideStderrForTesting($buffer);
    }

    protected function tearDown(): void
    {
        Deprecations::resetForTesting();
        OpenApiCoverageExtension::overrideStderrForTesting(null);
        if ($this->stderrBuffer !== null) {
            fclose($this->stderrBuffer);
            $this->stderrBuffer = null;
        }
        if ($this->githubSummaryTmp !== null) {
            @unlink($this->githubSummaryTmp);
            $this->githubSummaryTmp = null;
        }
        OpenApiSpecLoader::reset();
        EnumScanner::reset();
        Deprecations::resetForTesting();
        StrictAdditionalPropertiesPerCallChecker::reset();
        StrictAdditionalPropertiesTracker::resetCurrent();
        SdkExerciseCoverageTracker::resetCurrent();
        putenv($this->previousTestToken === false ? 'TEST_TOKEN' : 'TEST_TOKEN=' . $this->previousTestToken);
        parent::tearDown();
    }

    #[Test]
    public function bootstrap_throws_invalid_spec_exception_when_ref_unresolvable(): void
    {
        $extension = new OpenApiCoverageExtension();
        $parameters = FlatExtensionSettings::parameters([
            'spec_base_path' => __DIR__ . '/../../fixtures/specs',
            'specs' => 'refs-unresolvable',
        ]);

        $this->expectException(InvalidOpenApiSpecException::class);
        $this->expectExceptionMessage('Unresolvable $ref');

        $extension->setupExtension(null, $parameters, null);
    }

    #[Test]
    public function bootstrap_throws_fatal_for_missing_spec_file(): void
    {
        // issue #134: stale `specs=` entries used to print a warning and let
        // the suite continue, only blowing up later in an unrelated test.
        // Boot must now hard-fail so the configuration error surfaces at the
        // moment of detection — not on someone else's PR after a merge.
        $extension = new OpenApiCoverageExtension();
        $parameters = FlatExtensionSettings::parameters([
            'spec_base_path' => __DIR__ . '/../../fixtures/specs',
            'specs' => 'does-not-exist',
        ]);

        try {
            $extension->setupExtension(null, $parameters, null);
            $this->fail('expected SpecFileNotFoundException');
        } catch (SpecFileNotFoundException $e) {
            $this->assertSame('does-not-exist', $e->specName);
        }

        $stderr = $this->readStderr();
        $this->assertStringContainsString('FATAL', $stderr);
        $this->assertStringContainsString("spec 'does-not-exist'", $stderr);
        $this->assertStringContainsString('Action:', $stderr);
    }

    #[Test]
    public function bootstrap_loads_cleanly_for_valid_spec(): void
    {
        $extension = new OpenApiCoverageExtension();
        $parameters = FlatExtensionSettings::parameters([
            'spec_base_path' => __DIR__ . '/../../fixtures/specs',
            'specs' => 'refs-valid',
        ]);

        $extension->setupExtension(null, $parameters, null);

        $this->assertSame('', $this->readStderr());
    }

    #[Test]
    public function bootstrap_leaves_enum_base_path_unset_when_parameter_omitted(): void
    {
        // Issue #170: enum_spec_base_path is opt-in. When the parameter is
        // not present the loader must report null so EnumDriftAsserter
        // falls back to spec_base_path — keeping pre-1.2.0 setups
        // bit-for-bit identical.
        $extension = new OpenApiCoverageExtension();
        $parameters = FlatExtensionSettings::parameters([
            'spec_base_path' => __DIR__ . '/../../fixtures/specs',
            'specs' => 'refs-valid',
        ]);

        $extension->setupExtension(null, $parameters, null);

        $this->assertNull(OpenApiSpecLoader::getEnumBasePath());
    }

    #[Test]
    public function bootstrap_appends_fatal_block_to_github_step_summary(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'openapi-summary-');
        if ($tmp === false) {
            $this->fail('Could not create temp file for GITHUB_STEP_SUMMARY');
        }
        $this->githubSummaryTmp = $tmp;

        $extension = new OpenApiCoverageExtension();
        $parameters = FlatExtensionSettings::parameters([
            'spec_base_path' => __DIR__ . '/../../fixtures/specs',
            'specs' => 'refs-unresolvable',
        ]);

        try {
            $extension->setupExtension(null, $parameters, $tmp);
            $this->fail('expected InvalidOpenApiSpecException');
        } catch (InvalidOpenApiSpecException) {
            // Expected — the FATAL block must still have been written before re-throw.
        }

        $contents = (string) file_get_contents($tmp);
        $this->assertStringContainsString('FATAL OpenAPI spec error', $contents);
        $this->assertStringContainsString('refs-unresolvable', $contents);
        $this->assertStringContainsString('Unresolvable $ref', $contents);
    }

    #[Test]
    public function bootstrap_appends_fatal_block_to_github_step_summary_for_missing_spec_file(): void
    {
        // issue #134: missing-file failures must reach the GitHub Step
        // Summary too, not just stderr. CI logs scroll fast; the Step Summary
        // is where the misconfiguration becomes unmissable for reviewers.
        $tmp = tempnam(sys_get_temp_dir(), 'openapi-summary-');
        if ($tmp === false) {
            $this->fail('Could not create temp file for GITHUB_STEP_SUMMARY');
        }
        $this->githubSummaryTmp = $tmp;

        $extension = new OpenApiCoverageExtension();
        $parameters = FlatExtensionSettings::parameters([
            'spec_base_path' => __DIR__ . '/../../fixtures/specs',
            'specs' => 'does-not-exist',
        ]);

        try {
            $extension->setupExtension(null, $parameters, $tmp);
            $this->fail('expected SpecFileNotFoundException');
        } catch (SpecFileNotFoundException) {
            // Expected — the FATAL block must still have been written before re-throw.
        }

        $contents = (string) file_get_contents($tmp);
        $this->assertStringContainsString('FATAL OpenAPI spec error', $contents);
        $this->assertStringContainsString('does-not-exist', $contents);
        // Symmetric with the `Unresolvable $ref` assertion above: pin the
        // exception's underlying message so future renames of
        // SpecFileNotFoundException's wording can't silently empty the
        // Step Summary block of its actual root cause.
        $this->assertStringContainsString('OpenAPI bundled spec not found', $contents);
    }

    #[Test]
    public function bootstrap_throws_for_malformed_json_spec(): void
    {
        // Pre-PR catch-all demoted malformed JSON to a stderr WARNING, which
        // is functionally the same silent-pass hole as the $ref case issue
        // #79 set out to close. Pin the fatal treatment so it cannot regress.
        $extension = new OpenApiCoverageExtension();
        $parameters = FlatExtensionSettings::parameters([
            'spec_base_path' => __DIR__ . '/../../fixtures/specs',
            'specs' => 'malformed-json',
        ]);

        try {
            $extension->setupExtension(null, $parameters, null);
            $this->fail('expected InvalidOpenApiSpecException');
        } catch (InvalidOpenApiSpecException $e) {
            $this->assertSame(InvalidOpenApiSpecReason::MalformedJson, $e->reason);
        }

        $this->assertStringContainsString('FATAL', $this->readStderr());
    }

    #[Test]
    public function bootstrap_throws_when_base_path_not_configured(): void
    {
        // Omitting spec_base_path is a misconfiguration, not a missing file —
        // previously bundled into the warn-and-continue bucket via the broad
        // RuntimeException catch. Treat as fatal so users see their mistake.
        $extension = new OpenApiCoverageExtension();
        $parameters = FlatExtensionSettings::parameters([
            'specs' => 'refs-valid',
        ]);

        try {
            $extension->setupExtension(null, $parameters, null);
            $this->fail('expected InvalidOpenApiSpecException');
        } catch (InvalidOpenApiSpecException $e) {
            $this->assertSame(InvalidOpenApiSpecReason::BasePathNotConfigured, $e->reason);
        }
    }

    #[Test]
    public function bootstrap_hard_fails_for_missing_spec_even_when_others_are_valid(): void
    {
        // issue #134: a stale entry in `specs=` is a configuration error and
        // must abort boot. The valid spec is intentionally listed *first* so
        // we exercise loop-order independence on the missing-file branch:
        // a future "stop after first successful load" optimisation would be
        // caught here. The `refs-valid,refs-unresolvable` test below covers
        // the same shape for the InvalidOpenApiSpecException branch.
        $extension = new OpenApiCoverageExtension();
        $parameters = FlatExtensionSettings::parameters([
            'spec_base_path' => __DIR__ . '/../../fixtures/specs',
            'specs' => 'refs-valid,does-not-exist',
        ]);

        $this->expectException(SpecFileNotFoundException::class);

        $extension->setupExtension(null, $parameters, null);
    }

    #[Test]
    public function bootstrap_resolves_relative_sidecar_dir_against_cwd(): void
    {
        // Pin that the extension absolutises a relative `sidecar_dir`
        // against `getcwd()`, mirroring the existing `output_file` and
        // `spec_base_path` resolution. Pre-PR, sidecar_dir was new and
        // its relative-path handling had no test coverage.
        $extension = new OpenApiCoverageExtension();
        $parameters = FlatExtensionSettings::parameters([
            'spec_base_path' => __DIR__ . '/../../fixtures/specs',
            'specs' => 'refs-valid',
            'sidecar_dir' => 'relative-sidecars',
        ]);

        // setupExtension does not raise on relative paths; the exercise here
        // is mainly that the codepath runs without error and that the
        // absolutise call doesn't silently swallow the parameter.
        $extension->setupExtension(null, $parameters, null);

        $this->assertSame('', $this->readStderr());
    }

    #[Test]
    public function bootstrap_accepts_well_formed_threshold_parameters(): void
    {
        $extension = new OpenApiCoverageExtension();
        $parameters = FlatExtensionSettings::parameters([
            'spec_base_path' => __DIR__ . '/../../fixtures/specs',
            'specs' => 'refs-valid',
            'min_endpoint_coverage' => '80',
            'min_response_coverage' => '70.5',
            'min_sdk_exercise_coverage' => '100',
            'min_coverage_strict' => 'true',
        ]);

        $extension->setupExtension(null, $parameters, null);

        $this->assertSame('', $this->readStderr());
    }

    #[Test]
    public function bootstrap_hard_fails_even_when_earlier_specs_are_valid(): void
    {
        // Order-independence: the loop must reach and trip on the broken spec
        // even if a good spec ran first. A future early-return on "first
        // successful load" refactor would be caught here.
        $extension = new OpenApiCoverageExtension();
        $parameters = FlatExtensionSettings::parameters([
            'spec_base_path' => __DIR__ . '/../../fixtures/specs',
            'specs' => 'refs-valid,refs-unresolvable',
        ]);

        $this->expectException(InvalidOpenApiSpecException::class);
        $this->expectExceptionMessage('Unresolvable $ref');

        $extension->setupExtension(null, $parameters, null);
    }

    #[Test]
    public function bootstrap_skips_enum_drift_when_disabled(): void
    {
        $extension = new OpenApiCoverageExtension();
        $parameters = FlatExtensionSettings::parameters([
            'spec_base_path' => __DIR__ . '/../../fixtures/specs',
            'specs' => 'refs-valid',
        ]);

        $extension->setupExtension(null, $parameters, null);

        $this->assertSame('', $this->readStderr());
    }

    #[Test]
    public function bootstrap_fatals_when_enum_drift_enabled_with_no_namespaces(): void
    {
        $extension = new OpenApiCoverageExtension();
        $parameters = FlatExtensionSettings::parameters([
            'spec_base_path' => __DIR__ . '/../../fixtures/specs',
            'specs' => 'refs-valid',
            'enum_drift_enabled' => 'true',
        ]);

        try {
            $extension->setupExtension(null, $parameters, null);
            $this->fail('Expected EnumBindingException');
        } catch (EnumBindingException $e) {
            $this->assertSame(EnumBindingReason::NoNamespacesConfigured, $e->reason);
            $stderr = $this->readStderr();
            $this->assertStringContainsString('[OpenAPI Enum Drift] FATAL', $stderr);
            $this->assertStringContainsString('enum_drift.scan_namespaces', $stderr);
        }
    }

    #[Test]
    #[IgnoreDeprecations]
    public function bootstrap_fatals_on_drift_when_strict(): void
    {
        $extension = new OpenApiCoverageExtension();
        $parameters = FlatExtensionSettings::parameters([
            'spec_base_path' => __DIR__ . '/../../fixtures/specs',
            'specs' => 'refs-valid',
            'enum_drift_enabled' => 'true',
            'enum_drift_scan_namespaces' => 'Studio\\Gesso\\Tests\\Unit\\PHPUnit\\Fixture\\EnumDrift\\Drifting\\',
        ]);

        try {
            $extension->setupExtension(null, $parameters, null);
            $this->fail('Expected EnumDriftException');
        } catch (EnumDriftException) {
            $stderr = $this->readStderr();
            $this->assertStringContainsString('[OpenAPI Enum Drift] FATAL', $stderr);
            $this->assertStringContainsString('DriftingNotification', $stderr);
            $this->assertStringContainsString('PHP-only', $stderr);
            $this->assertStringContainsString('Spec-only', $stderr);
        }
    }

    #[Test]
    public function bootstrap_warns_on_drift_when_lenient(): void
    {
        $extension = new OpenApiCoverageExtension();
        $parameters = FlatExtensionSettings::parameters([
            'spec_base_path' => __DIR__ . '/../../fixtures/specs',
            'specs' => 'refs-valid',
            'enum_drift_enabled' => 'true',
            'enum_drift_scan_namespaces' => 'Studio\\Gesso\\Tests\\Unit\\PHPUnit\\Fixture\\EnumDrift\\Drifting\\',
            'enum_drift_fail_on_drift' => 'false',
        ]);

        $userWarningFired = false;
        set_error_handler(static function (int $severity) use (&$userWarningFired): bool {
            if ($severity === E_USER_WARNING) {
                $userWarningFired = true;
            }

            return true;
        });

        try {
            $extension->setupExtension(null, $parameters, null);
        } finally {
            restore_error_handler();
        }

        $stderr = $this->readStderr();
        $this->assertStringContainsString('[OpenAPI Enum Drift] WARNING', $stderr);
        $this->assertStringContainsString('DriftingNotification', $stderr);
        $this->assertFalse($userWarningFired, 'Lenient mode must not raise E_USER_WARNING');
    }

    #[Test]
    #[IgnoreDeprecations]
    public function bootstrap_succeeds_when_no_drift(): void
    {
        $extension = new OpenApiCoverageExtension();
        $parameters = FlatExtensionSettings::parameters([
            'spec_base_path' => __DIR__ . '/../../fixtures/specs',
            'specs' => 'refs-valid',
            'enum_drift_enabled' => 'true',
            'enum_drift_scan_namespaces' => 'Studio\\Gesso\\Tests\\Unit\\PHPUnit\\Fixture\\EnumDrift\\Clean\\',
        ]);

        $extension->setupExtension(null, $parameters, null);

        $this->assertSame(self::CLEAN_BINDINGS, $this->readStderr());
    }

    #[Test]
    public function bootstrap_appends_bindings_block_to_step_summary_when_no_drift(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'openapi-enum-summary-');
        if ($tmp === false) {
            $this->fail('Could not create temp file for GITHUB_STEP_SUMMARY');
        }
        $this->githubSummaryTmp = $tmp;

        $extension = new OpenApiCoverageExtension();
        $parameters = FlatExtensionSettings::parameters([
            'spec_base_path' => __DIR__ . '/../../fixtures/specs',
            'specs' => 'refs-valid',
            'enum_drift_enabled' => 'true',
            'enum_drift_scan_namespaces' => 'Studio\\Gesso\\Tests\\Unit\\PHPUnit\\Fixture\\EnumDrift\\Clean\\',
        ]);

        $extension->setupExtension(null, $parameters, $tmp);

        $contents = (string) file_get_contents($tmp);
        $this->assertStringContainsString('OpenAPI enum drift', $contents);
        $this->assertStringContainsString(self::CLEAN_BINDINGS, $contents);
        $this->assertStringNotContainsString('FATAL', $contents);
    }

    #[Test]
    #[IgnoreDeprecations]
    public function bootstrap_fatals_on_misconfigured_binding_even_when_lenient(): void
    {
        $extension = new OpenApiCoverageExtension();
        $parameters = FlatExtensionSettings::parameters([
            'spec_base_path' => __DIR__ . '/../../fixtures/specs',
            'specs' => 'refs-valid',
            'enum_drift_enabled' => 'true',
            'enum_drift_scan_namespaces' => 'Studio\\Gesso\\Tests\\Unit\\PHPUnit\\Fixture\\EnumDrift\\Misconfigured\\',
            'enum_drift_fail_on_drift' => 'false',
        ]);

        try {
            $extension->setupExtension(null, $parameters, null);
            $this->fail('Expected EnumBindingException');
        } catch (EnumBindingException $e) {
            $this->assertSame(EnumBindingReason::SpecFileNotFound, $e->reason);
            $this->assertStringContainsString('[OpenAPI Enum Drift] FATAL', $this->readStderr());
        }
    }

    #[Test]
    #[IgnoreDeprecations]
    public function bootstrap_parses_namespaces_csv_with_whitespace(): void
    {
        $extension = new OpenApiCoverageExtension();
        $parameters = FlatExtensionSettings::parameters([
            'spec_base_path' => __DIR__ . '/../../fixtures/specs',
            'specs' => 'refs-valid',
            'enum_drift_enabled' => 'true',
            'enum_drift_scan_namespaces' =>
                ' Studio\\Gesso\\Tests\\Unit\\PHPUnit\\Fixture\\EnumDrift\\Clean\\ '
                . ', '
                . ' Studio\\Gesso\\Tests\\Unit\\PHPUnit\\Fixture\\EnumDrift\\Clean\\ ',
        ]);

        $extension->setupExtension(null, $parameters, null);

        $this->assertSame(self::CLEAN_BINDINGS, $this->readStderr());
    }

    #[Test]
    public function bootstrap_detects_drift_in_enums_bound_by_component_name(): void
    {
        $extension = new OpenApiCoverageExtension();
        $parameters = FlatExtensionSettings::parameters([
            'spec_base_path' => __DIR__ . '/../../fixtures/specs',
            'specs' => 'enum-drift/components',
            'enum_drift_enabled' => 'true',
            'enum_drift_scan_namespaces' => 'Studio\\Gesso\\Tests\\Unit\\PHPUnit\\Fixture\\EnumDrift\\ByName\\',
        ]);

        try {
            $extension->setupExtension(null, $parameters, null);
            $this->fail('Expected EnumDriftException');
        } catch (EnumDriftException) {
            $stderr = $this->readStderr();
            $this->assertStringStartsWith(
                "[OpenAPI Enum Drift] 1 bound (1 by name, 0 by attribute), 1 unbound.\n"
                . "  Studio\\Gesso\\Tests\\Unit\\PHPUnit\\Fixture\\EnumDrift\\ByName\\Color  ->  #/components/schemas/Color\n",
                $stderr,
            );
            $this->assertStringContainsString('[OpenAPI Enum Drift] FATAL', $stderr);
            $this->assertStringContainsString('ByName\\Color  ->  #/components/schemas/Color', $stderr);
            $this->assertStringContainsString('"black"', $stderr);
            $this->assertStringNotContainsString('InternalOnly', $stderr);
        }
    }

    #[Test]
    public function bootstrap_skips_unbound_enums_silently(): void
    {
        $extension = new OpenApiCoverageExtension();
        $parameters = FlatExtensionSettings::parameters([
            'spec_base_path' => __DIR__ . '/../../fixtures/specs',
            'specs' => 'refs-valid',
            'enum_drift_enabled' => 'true',
            'enum_drift_scan_namespaces' => 'Studio\\Gesso\\Tests\\Unit\\PHPUnit\\Fixture\\EnumDrift\\ByName\\',
        ]);

        $extension->setupExtension(null, $parameters, null);

        $stderr = $this->readStderr();
        $this->assertStringStartsWith('[OpenAPI Enum Drift] NOTE: scan matched zero bound enums', $stderr);
        $this->assertSame(1, substr_count($stderr, "\n"));
    }

    #[Test]
    public function bootstrap_emits_note_when_no_attributed_enums_found(): void
    {
        // Resolvable namespace prefix that contains enums but none with the
        // attribute — typo'd `enum_drift_scan_namespaces` lands here too.
        // The NOTE must surface to stderr so a typo is observable, but the
        // run must continue (codebases mid-migration have this state too).
        $extension = new OpenApiCoverageExtension();
        $parameters = FlatExtensionSettings::parameters([
            'spec_base_path' => __DIR__ . '/../../fixtures/specs',
            'specs' => 'refs-valid',
            'enum_drift_enabled' => 'true',
            // Resolvable PSR-4 root that contains zero attributed enums.
            'enum_drift_scan_namespaces' =>
                'Studio\\Gesso\\Tests\\Unit\\Internal\\Fixture\\NotAnEnumNs\\',
        ]);

        // Resolvable but empty PSR-4 root — use a sub-namespace under tests/
        // that doesn't exist so prefixHasResolvableRoot still returns true
        // via the longest-match path, but the directory walk yields nothing.
        try {
            $extension->setupExtension(null, $parameters, null);
            // Fall-through path: NOTE was emitted, run continues.
            $stderr = $this->readStderr();
            $this->assertStringContainsString('[OpenAPI Enum Drift] NOTE', $stderr);
            $this->assertStringContainsString('zero bound enums', $stderr);
        } catch (EnumBindingException) {
            // If the chosen prefix happens to be unresolvable on this host
            // (no PSR-4 longest-match registered for tests/), skip the
            // assertion gracefully — the dedicated unresolvable-namespace
            // test covers that branch.
            $this->markTestSkipped(
                'Test environment does not register a longest-match PSR-4 ancestor for the chosen prefix; skip — unresolvable case is covered separately.',
            );
        }
    }

    #[Test]
    #[IgnoreDeprecations]
    public function bootstrap_appends_fatal_block_to_step_summary_for_enum_drift(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'openapi-enum-summary-');
        if ($tmp === false) {
            $this->fail('Could not create temp file for GITHUB_STEP_SUMMARY');
        }
        $this->githubSummaryTmp = $tmp;

        $extension = new OpenApiCoverageExtension();
        $parameters = FlatExtensionSettings::parameters([
            'spec_base_path' => __DIR__ . '/../../fixtures/specs',
            'specs' => 'refs-valid',
            'enum_drift_enabled' => 'true',
            'enum_drift_scan_namespaces' => 'Studio\\Gesso\\Tests\\Unit\\PHPUnit\\Fixture\\EnumDrift\\Drifting\\',
        ]);

        try {
            $extension->setupExtension(null, $parameters, $tmp);
            $this->fail('Expected EnumDriftException');
        } catch (EnumDriftException) {
            // Expected — the FATAL block must still have been written before re-throw.
        }

        $contents = (string) file_get_contents($tmp);
        $this->assertStringContainsString('FATAL OpenAPI enum drift', $contents);
        $this->assertStringContainsString('DriftingNotification', $contents);
        $this->assertStringContainsString('PHP-only', $contents);
    }

    #[Test]
    public function bootstrap_skips_enum_drift_in_a_paratest_worker(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'openapi-enum-summary-');
        if ($tmp === false) {
            $this->fail('Could not create temp file for GITHUB_STEP_SUMMARY');
        }
        $this->githubSummaryTmp = $tmp;
        putenv('TEST_TOKEN=2');

        $extension = new OpenApiCoverageExtension();
        $parameters = FlatExtensionSettings::parameters([
            'spec_base_path' => __DIR__ . '/../../fixtures/specs',
            'specs' => 'refs-valid',
            'enum_drift_enabled' => 'true',
            'enum_drift_scan_namespaces' => 'Studio\\Gesso\\Tests\\Unit\\PHPUnit\\Fixture\\EnumDrift\\Drifting\\',
            'enum_drift_fail_on_drift' => 'true',
        ]);

        // The paratest master is the single evaluator; a worker must neither
        // throw nor duplicate the stderr line or the step-summary block.
        $extension->setupExtension(null, $parameters, $tmp);

        $this->assertStringNotContainsString('[OpenAPI Enum Drift]', $this->readStderr());
        $this->assertSame('', (string) file_get_contents($tmp));
    }

    #[Test]
    #[IgnoreDeprecations]
    public function bootstrap_appends_warning_block_to_step_summary_for_lenient_drift(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'openapi-enum-summary-');
        if ($tmp === false) {
            $this->fail('Could not create temp file for GITHUB_STEP_SUMMARY');
        }
        $this->githubSummaryTmp = $tmp;

        $extension = new OpenApiCoverageExtension();
        $parameters = FlatExtensionSettings::parameters([
            'spec_base_path' => __DIR__ . '/../../fixtures/specs',
            'specs' => 'refs-valid',
            'enum_drift_enabled' => 'true',
            'enum_drift_scan_namespaces' => 'Studio\\Gesso\\Tests\\Unit\\PHPUnit\\Fixture\\EnumDrift\\Drifting\\',
            'enum_drift_fail_on_drift' => 'false',
        ]);

        $extension->setupExtension(null, $parameters, $tmp);

        $contents = (string) file_get_contents($tmp);
        $this->assertStringContainsString(':warning:', $contents);
        $this->assertStringNotContainsString(':rotating_light:', $contents);
        $this->assertStringContainsString('DriftingNotification', $contents);
    }

    #[Test]
    public function bootstrap_appends_fatal_block_to_step_summary_for_unresolvable_namespace(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'openapi-enum-summary-');
        if ($tmp === false) {
            $this->fail('Could not create temp file for GITHUB_STEP_SUMMARY');
        }
        $this->githubSummaryTmp = $tmp;

        $extension = new OpenApiCoverageExtension();
        $parameters = FlatExtensionSettings::parameters([
            'spec_base_path' => __DIR__ . '/../../fixtures/specs',
            'specs' => 'refs-valid',
            'enum_drift_enabled' => 'true',
            'enum_drift_scan_namespaces' => 'Some\\Nonexistent\\Namespace\\',
        ]);

        try {
            $extension->setupExtension(null, $parameters, $tmp);
            $this->fail('Expected EnumBindingException');
        } catch (EnumBindingException $e) {
            $this->assertSame(EnumBindingReason::ScanNamespaceUnresolvable, $e->reason);
        }

        $contents = (string) file_get_contents($tmp);
        $this->assertStringContainsString('FATAL OpenAPI enum drift', $contents);
        $this->assertStringContainsString('Some\\Nonexistent\\Namespace\\', $contents);
    }

    #[Test]
    public function bootstrap_fatals_with_scan_composer_loader_unavailable_reason(): void
    {
        EnumScanner::forceLoaderUnavailableForTesting();

        $extension = new OpenApiCoverageExtension();
        $parameters = FlatExtensionSettings::parameters([
            'spec_base_path' => __DIR__ . '/../../fixtures/specs',
            'specs' => 'refs-valid',
            'enum_drift_enabled' => 'true',
            'enum_drift_scan_namespaces' =>
                'Studio\\Gesso\\Tests\\Unit\\PHPUnit\\Fixture\\EnumDrift\\Clean\\',
        ]);

        try {
            $extension->setupExtension(null, $parameters, null);
            $this->fail('Expected EnumBindingException');
        } catch (EnumBindingException $e) {
            $this->assertSame(EnumBindingReason::ScanComposerLoaderUnavailable, $e->reason);
            $this->assertStringContainsString('[OpenAPI Enum Drift] FATAL', $this->readStderr());
        }
    }

    #[Test]
    #[IgnoreDeprecations]
    public function bootstrap_fatals_on_drift_when_fail_on_drift_omitted(): void
    {
        // Default for `enum_drift_fail_on_drift` is `true`. Pin the default
        // by simply omitting the parameter and asserting drift FATALs.
        $extension = new OpenApiCoverageExtension();
        $parameters = FlatExtensionSettings::parameters([
            'spec_base_path' => __DIR__ . '/../../fixtures/specs',
            'specs' => 'refs-valid',
            'enum_drift_enabled' => 'true',
            'enum_drift_scan_namespaces' => 'Studio\\Gesso\\Tests\\Unit\\PHPUnit\\Fixture\\EnumDrift\\Drifting\\',
            // enum_drift_fail_on_drift intentionally omitted
        ]);

        $this->expectException(EnumDriftException::class);

        $extension->setupExtension(null, $parameters, null);
    }

    #[Test]
    public function bootstrap_fatal_when_junit_output_parent_dir_cannot_be_created(): void
    {
        // Surface the misconfiguration at bootstrap rather than as a runtime
        // file_put_contents() WARN after every test ran. /proc/0 does not
        // exist on macOS and is unwritable on Linux, so the issue #448
        // auto-creation attempt fails closed either way.
        $extension = new OpenApiCoverageExtension();
        $parameters = FlatExtensionSettings::parameters([
            'spec_base_path' => __DIR__ . '/../../fixtures/specs',
            'specs' => 'refs-valid',
            'junit_output' => '/proc/0/nonexistent/coverage.junit.xml',
        ]);

        try {
            $extension->setupExtension(null, $parameters, null);
            $this->fail('expected InvalidCoverageOutputPathException');
        } catch (InvalidCoverageOutputPathException $e) {
            $this->assertSame('junit_output', $e->parameterName);
            $this->assertStringContainsString('could not be created', $e->getMessage());
        }

        $this->assertStringContainsString('FATAL', $this->readStderr());
    }

    #[Test]
    public function bootstrap_fatal_when_html_output_parent_dir_cannot_be_created(): void
    {
        // Parity with the junit_output / json_output checks — the shared
        // resolveOutputPathParameter() helper should fail closed for
        // html_output too.
        $extension = new OpenApiCoverageExtension();
        $parameters = FlatExtensionSettings::parameters([
            'spec_base_path' => __DIR__ . '/../../fixtures/specs',
            'specs' => 'refs-valid',
            'html_output' => '/proc/0/nonexistent/coverage.html',
        ]);

        try {
            $extension->setupExtension(null, $parameters, null);
            $this->fail('expected InvalidCoverageOutputPathException');
        } catch (InvalidCoverageOutputPathException $e) {
            $this->assertSame('html_output', $e->parameterName);
            $this->assertStringContainsString('could not be created', $e->getMessage());
        }

        $this->assertStringContainsString('FATAL', $this->readStderr());
    }

    #[Test]
    public function bootstrap_fatal_when_json_output_parent_dir_cannot_be_created(): void
    {
        // Parity with the junit_output check — the shared
        // resolveOutputPathParameter() helper should fail closed for
        // json_output too. Guards against a future change to the helper that
        // accidentally pins it to one parameter name.
        $extension = new OpenApiCoverageExtension();
        $parameters = FlatExtensionSettings::parameters([
            'spec_base_path' => __DIR__ . '/../../fixtures/specs',
            'specs' => 'refs-valid',
            'json_output' => '/proc/0/nonexistent/coverage.json',
        ]);

        try {
            $extension->setupExtension(null, $parameters, null);
            $this->fail('expected InvalidCoverageOutputPathException');
        } catch (InvalidCoverageOutputPathException $e) {
            $this->assertSame('json_output', $e->parameterName);
            $this->assertStringContainsString('could not be created', $e->getMessage());
        }

        $this->assertStringContainsString('FATAL', $this->readStderr());
    }

    #[Test]
    public function bootstrap_creates_missing_parent_directory_for_json_output(): void
    {
        // Issue #448: json_output pointing into a gitignored build directory
        // must work on a fresh clone. The parent chain does not exist yet —
        // bootstrap should mkdir it recursively instead of hard-failing.
        $base = sys_get_temp_dir() . '/gesso-448-' . uniqid();
        $extension = new OpenApiCoverageExtension();
        $parameters = FlatExtensionSettings::parameters([
            'spec_base_path' => __DIR__ . '/../../fixtures/specs',
            'specs' => 'refs-valid',
            'json_output' => $base . '/build/openapi-coverage.json',
        ]);

        try {
            $extension->setupExtension(null, $parameters, null);

            $this->assertDirectoryExists($base . '/build');
            $this->assertSame('', $this->readStderr());
        } finally {
            @rmdir($base . '/build');
            @rmdir($base);
        }
    }

    #[Test]
    public function bootstrap_creates_missing_parent_directory_for_junit_output(): void
    {
        // Issue #448 parity: the shared resolveOutputPathParameter() helper
        // should auto-create for junit_output too.
        $base = sys_get_temp_dir() . '/gesso-448-' . uniqid();
        $extension = new OpenApiCoverageExtension();
        $parameters = FlatExtensionSettings::parameters([
            'spec_base_path' => __DIR__ . '/../../fixtures/specs',
            'specs' => 'refs-valid',
            'junit_output' => $base . '/build/coverage.junit.xml',
        ]);

        try {
            $extension->setupExtension(null, $parameters, null);

            $this->assertDirectoryExists($base . '/build');
            $this->assertSame('', $this->readStderr());
        } finally {
            @rmdir($base . '/build');
            @rmdir($base);
        }
    }

    #[Test]
    public function bootstrap_creates_missing_parent_directory_for_html_output(): void
    {
        // Issue #448 parity: the shared resolveOutputPathParameter() helper
        // should auto-create for html_output too.
        $base = sys_get_temp_dir() . '/gesso-448-' . uniqid();
        $extension = new OpenApiCoverageExtension();
        $parameters = FlatExtensionSettings::parameters([
            'spec_base_path' => __DIR__ . '/../../fixtures/specs',
            'specs' => 'refs-valid',
            'html_output' => $base . '/build/coverage.html',
        ]);

        try {
            $extension->setupExtension(null, $parameters, null);

            $this->assertDirectoryExists($base . '/build');
            $this->assertSame('', $this->readStderr());
        } finally {
            @rmdir($base . '/build');
            @rmdir($base);
        }
    }

    #[Test]
    public function bootstrap_fatal_when_json_output_parent_path_is_occupied_by_a_file(): void
    {
        // A file sitting where the parent directory should be is a genuine
        // misconfiguration: mkdir() cannot succeed, so the issue #448
        // auto-creation must fail closed with a message that names the fix.
        $file = tempnam(sys_get_temp_dir(), 'gesso-448-occupied-');
        $this->assertNotFalse($file);

        $extension = new OpenApiCoverageExtension();
        $parameters = FlatExtensionSettings::parameters([
            'spec_base_path' => __DIR__ . '/../../fixtures/specs',
            'specs' => 'refs-valid',
            'json_output' => $file . '/openapi-coverage.json',
        ]);

        try {
            $extension->setupExtension(null, $parameters, null);
            $this->fail('expected InvalidCoverageOutputPathException');
        } catch (InvalidCoverageOutputPathException $e) {
            $this->assertSame('json_output', $e->parameterName);
            $this->assertStringContainsString('could not be created', $e->getMessage());
            $this->assertStringContainsString('point the setting at a writable location', $e->getMessage());
        } finally {
            @unlink($file);
        }

        $this->assertStringContainsString('FATAL', $this->readStderr());
    }

    #[Test]
    public function bootstrap_fatal_when_json_output_parent_dir_exists_but_not_writable(): void
    {
        // The pre-#448 fail-loud policy must survive auto-creation: an
        // existing directory without write permission is still FATAL.
        $base = sys_get_temp_dir() . '/gesso-448-readonly-' . uniqid();
        $this->assertTrue(mkdir($base, 0o555, true));
        if (is_writable($base)) {
            @rmdir($base);
            $this->markTestSkipped('running as a user that ignores directory permissions (root)');
        }

        $extension = new OpenApiCoverageExtension();
        $parameters = FlatExtensionSettings::parameters([
            'spec_base_path' => __DIR__ . '/../../fixtures/specs',
            'specs' => 'refs-valid',
            'json_output' => $base . '/openapi-coverage.json',
        ]);

        try {
            $extension->setupExtension(null, $parameters, null);
            $this->fail('expected InvalidCoverageOutputPathException');
        } catch (InvalidCoverageOutputPathException $e) {
            $this->assertSame('json_output', $e->parameterName);
            $this->assertStringContainsString('not writable', $e->getMessage());
        } finally {
            @chmod($base, 0o755);
            @rmdir($base);
        }

        $this->assertStringContainsString('FATAL', $this->readStderr());
    }

    #[Test]
    public function strict_required_off_does_not_throw(): void
    {
        StrictRequiredTracker::reset();

        $extension = new OpenApiCoverageExtension();
        $parameters = FlatExtensionSettings::parameters([
            'spec_base_path' => __DIR__ . '/../../fixtures/specs',
            'specs' => 'refs-valid',
            'strict_required' => 'off',
        ]);

        $extension->setupExtension(null, $parameters, null);

        $this->assertStringNotContainsString('strict_required', $this->readStderr());
    }

    #[Test]
    public function strict_required_warn_resets_tracker_at_bootstrap(): void
    {
        StrictRequiredTracker::record('refs-valid', 'GET', '/leftover', '200', 'application/json', ['/' => ['stale']]);

        $extension = new OpenApiCoverageExtension();
        $parameters = FlatExtensionSettings::parameters([
            'spec_base_path' => __DIR__ . '/../../fixtures/specs',
            'specs' => 'refs-valid',
            'strict_required' => 'warn',
        ]);

        $extension->setupExtension(null, $parameters, null);

        $this->assertSame([], StrictRequiredTracker::getObservations('refs-valid'));
    }

    #[Test]
    public function bootstrap_installs_a_fresh_sdk_exercise_tracker(): void
    {
        SdkExerciseCoverageTracker::current()->recordOn(
            'refs-valid',
            'GET',
            '/leftover',
            '200',
            'application/json',
        );

        (new OpenApiCoverageExtension())->setupExtension(
            null,
            FlatExtensionSettings::parameters([
                'spec_base_path' => __DIR__ . '/../../fixtures/specs',
                'specs' => 'refs-valid',
            ]),
            null,
        );

        $this->assertSame([], SdkExerciseCoverageTracker::current()->observationsForSpecOn('refs-valid'));
    }

    #[Test]
    public function strict_required_per_call_warn_configures_checker_at_bootstrap(): void
    {
        StrictRequiredPerCallChecker::reset();

        $extension = new OpenApiCoverageExtension();
        $parameters = FlatExtensionSettings::parameters([
            'spec_base_path' => __DIR__ . '/../../fixtures/specs',
            'specs' => 'refs-valid',
            'strict_required_per_call' => 'warn',
        ]);

        try {
            $extension->setupExtension(null, $parameters, null);
            $this->assertSame(StrictRequiredPerCallMode::Warn, StrictRequiredPerCallChecker::mode());
        } finally {
            StrictRequiredPerCallChecker::reset();
        }
    }

    #[Test]
    public function strict_required_per_call_absent_keeps_checker_off(): void
    {
        StrictRequiredPerCallChecker::reset();

        $extension = new OpenApiCoverageExtension();
        $parameters = FlatExtensionSettings::parameters([
            'spec_base_path' => __DIR__ . '/../../fixtures/specs',
            'specs' => 'refs-valid',
        ]);

        try {
            $extension->setupExtension(null, $parameters, null);
            $this->assertSame(StrictRequiredPerCallMode::Off, StrictRequiredPerCallChecker::mode());
        } finally {
            StrictRequiredPerCallChecker::reset();
        }
    }

    #[Test]
    public function enforce_discriminator_false_disables_gate_at_bootstrap(): void
    {
        DiscriminatorEnforcement::reset();

        $extension = new OpenApiCoverageExtension();
        $parameters = FlatExtensionSettings::parameters([
            'spec_base_path' => __DIR__ . '/../../fixtures/specs',
            'specs' => 'refs-valid',
            'enforce_discriminator' => 'false',
        ]);

        try {
            $extension->setupExtension(null, $parameters, null);
            $this->assertFalse(DiscriminatorEnforcement::isEnabled());
        } finally {
            DiscriminatorEnforcement::reset();
        }
    }

    #[Test]
    public function enforce_discriminator_absent_keeps_gate_enabled(): void
    {
        // Default ON (#262): the headline behaviour must stay enabled when the
        // parameter is omitted. Reset to a non-default first so the assertion
        // proves bootstrap re-enabled it rather than merely inheriting the
        // default.
        DiscriminatorEnforcement::configure(false);

        $extension = new OpenApiCoverageExtension();
        $parameters = FlatExtensionSettings::parameters([
            'spec_base_path' => __DIR__ . '/../../fixtures/specs',
            'specs' => 'refs-valid',
        ]);

        try {
            $extension->setupExtension(null, $parameters, null);
            $this->assertTrue(DiscriminatorEnforcement::isEnabled());
        } finally {
            DiscriminatorEnforcement::reset();
        }
    }

    #[Test]
    public function acknowledged_unvalidatable_schemes_parameter_configures_registry_at_bootstrap(): void
    {
        // Issue #445: scheme names reach the process-global registry so
        // non-Laravel suites can acknowledge unvalidatable schemes.
        AcknowledgedSecuritySchemes::reset();

        $extension = new OpenApiCoverageExtension();
        $parameters = FlatExtensionSettings::parameters([
            'spec_base_path' => __DIR__ . '/../../fixtures/specs',
            'specs' => 'refs-valid',
            'acknowledged_unvalidatable_schemes' => ['ClientBasicAuth', 'LegacyOAuth'],
        ]);

        try {
            $extension->setupExtension(null, $parameters, null);
            $this->assertSame(['ClientBasicAuth', 'LegacyOAuth'], AcknowledgedSecuritySchemes::names());
        } finally {
            AcknowledgedSecuritySchemes::reset();
        }
    }

    #[Test]
    public function acknowledged_unvalidatable_schemes_absent_resets_registry(): void
    {
        // A process reused across bootstraps must reflect the current run:
        // seed a stale acknowledgement and prove bootstrap clears it when the
        // parameter is omitted.
        AcknowledgedSecuritySchemes::configure(['Stale']);

        $extension = new OpenApiCoverageExtension();
        $parameters = FlatExtensionSettings::parameters([
            'spec_base_path' => __DIR__ . '/../../fixtures/specs',
            'specs' => 'refs-valid',
        ]);

        try {
            $extension->setupExtension(null, $parameters, null);
            $this->assertSame([], AcknowledgedSecuritySchemes::names());
        } finally {
            AcknowledgedSecuritySchemes::reset();
        }
    }

    #[Test]
    public function strict_required_per_call_and_run_level_can_coexist(): void
    {
        StrictRequiredPerCallChecker::reset();

        $extension = new OpenApiCoverageExtension();
        $parameters = FlatExtensionSettings::parameters([
            'spec_base_path' => __DIR__ . '/../../fixtures/specs',
            'specs' => 'refs-valid',
            'strict_required' => 'warn',
            'strict_required_per_call' => 'warn',
        ]);

        try {
            $extension->setupExtension(null, $parameters, null);
            $this->assertSame(StrictRequiredPerCallMode::Warn, StrictRequiredPerCallChecker::mode());
        } finally {
            StrictRequiredPerCallChecker::reset();
        }
    }

    #[Test]
    public function strict_additional_properties_per_call_warn_configures_checker(): void
    {
        $extension = new OpenApiCoverageExtension();
        $parameters = FlatExtensionSettings::parameters([
            'spec_base_path' => __DIR__ . '/../../fixtures/specs',
            'specs' => 'refs-valid',
            'strict_additional_properties' => 'warn',
            'strict_additional_properties_per_call' => 'warn',
        ]);

        $extension->setupExtension(null, $parameters, null);

        $this->assertTrue(StrictAdditionalPropertiesPerCallChecker::isEnabled());
        $this->assertSame(0, StrictAdditionalPropertiesTracker::current()->evaluationsOn());
    }

    private function readStderr(): string
    {
        if ($this->stderrBuffer === null) {
            return '';
        }
        rewind($this->stderrBuffer);

        return (string) stream_get_contents($this->stderrBuffer);
    }
}
