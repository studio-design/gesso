<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Tests\Unit\PHPUnit;

use const E_USER_WARNING;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use PHPUnit\Runner\Extension\ParameterCollection;
use Studio\OpenApiContractTesting\Coverage\InvalidCoverageOutputPathException;
use Studio\OpenApiContractTesting\Coverage\InvalidThresholdConfigurationException;
use Studio\OpenApiContractTesting\Exception\EnumBindingException;
use Studio\OpenApiContractTesting\Exception\EnumBindingReason;
use Studio\OpenApiContractTesting\Exception\EnumDriftException;
use Studio\OpenApiContractTesting\Exception\InvalidOpenApiSpecException;
use Studio\OpenApiContractTesting\Exception\InvalidOpenApiSpecReason;
use Studio\OpenApiContractTesting\Exception\SpecFileNotFoundException;
use Studio\OpenApiContractTesting\Internal\EnumScanner;
use Studio\OpenApiContractTesting\PHPUnit\InvalidStrictRequiredConfigurationException;
use Studio\OpenApiContractTesting\PHPUnit\OpenApiCoverageExtension;
use Studio\OpenApiContractTesting\Spec\OpenApiSpecLoader;
use Studio\OpenApiContractTesting\Validation\Strict\StrictRequiredPerCallChecker;
use Studio\OpenApiContractTesting\Validation\Strict\StrictRequiredPerCallMode;
use Studio\OpenApiContractTesting\Validation\Strict\StrictRequiredTracker;

use function fclose;
use function file_get_contents;
use function fopen;
use function getcwd;
use function restore_error_handler;
use function rewind;
use function set_error_handler;
use function stream_get_contents;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

class OpenApiCoverageExtensionTest extends TestCase
{
    /** @var null|resource */
    private $stderrBuffer;
    private ?string $githubSummaryTmp = null;

    protected function setUp(): void
    {
        parent::setUp();
        OpenApiSpecLoader::reset();
        EnumScanner::reset();

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
        if ($this->githubSummaryTmp !== null) {
            @unlink($this->githubSummaryTmp);
            $this->githubSummaryTmp = null;
        }
        OpenApiSpecLoader::reset();
        EnumScanner::reset();
        parent::tearDown();
    }

    #[Test]
    public function bootstrap_throws_invalid_spec_exception_when_ref_unresolvable(): void
    {
        $extension = new OpenApiCoverageExtension();
        $parameters = ParameterCollection::fromArray([
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
        $parameters = ParameterCollection::fromArray([
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
        $parameters = ParameterCollection::fromArray([
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
        $parameters = ParameterCollection::fromArray([
            'spec_base_path' => __DIR__ . '/../../fixtures/specs',
            'specs' => 'refs-valid',
        ]);

        $extension->setupExtension(null, $parameters, null);

        $this->assertNull(OpenApiSpecLoader::getEnumBasePath());
    }

    #[Test]
    public function bootstrap_passes_absolute_enum_spec_base_path_to_loader(): void
    {
        $extension = new OpenApiCoverageExtension();
        $parameters = ParameterCollection::fromArray([
            'spec_base_path' => __DIR__ . '/../../fixtures/specs',
            'specs' => 'refs-valid',
            'enum_spec_base_path' => '/absolute/path/to/openapi',
        ]);

        $extension->setupExtension(null, $parameters, null);

        $this->assertSame('/absolute/path/to/openapi', OpenApiSpecLoader::getEnumBasePath());
    }

    #[Test]
    public function bootstrap_resolves_relative_enum_spec_base_path_against_cwd(): void
    {
        // Pin that the extension absolutises a relative `enum_spec_base_path`
        // against `getcwd()`, mirroring `spec_base_path` / `output_file` /
        // `sidecar_dir`. Without this the asserter would silently look in the
        // wrong place when paratest workers run with a different cwd.
        $extension = new OpenApiCoverageExtension();
        $parameters = ParameterCollection::fromArray([
            'spec_base_path' => __DIR__ . '/../../fixtures/specs',
            'specs' => 'refs-valid',
            'enum_spec_base_path' => 'relative/openapi',
        ]);

        $extension->setupExtension(null, $parameters, null);

        $this->assertSame(getcwd() . '/relative/openapi', OpenApiSpecLoader::getEnumBasePath());
    }

    #[Test]
    public function bootstrap_trims_whitespace_around_enum_spec_base_path(): void
    {
        // XML editors sometimes wrap parameter values in stray newlines or
        // spaces. Without trim() those would silently flow as
        // `getcwd()."/   /openapi"` and produce a confusing
        // EnumBasePathNotFound diagnostic later.
        $extension = new OpenApiCoverageExtension();
        $parameters = ParameterCollection::fromArray([
            'spec_base_path' => __DIR__ . '/../../fixtures/specs',
            'specs' => 'refs-valid',
            'enum_spec_base_path' => "  \n /absolute/path/to/openapi  \n",
        ]);

        $extension->setupExtension(null, $parameters, null);

        $this->assertSame('/absolute/path/to/openapi', OpenApiSpecLoader::getEnumBasePath());
    }

    #[Test]
    public function bootstrap_fatal_when_enum_spec_base_path_is_empty(): void
    {
        // <parameter name="enum_spec_base_path" value=""/> would otherwise
        // flow as the empty string and silently coerce to getcwd(), masking
        // the misconfiguration.
        $extension = new OpenApiCoverageExtension();
        $parameters = ParameterCollection::fromArray([
            'spec_base_path' => __DIR__ . '/../../fixtures/specs',
            'specs' => 'refs-valid',
            'enum_spec_base_path' => '',
        ]);

        try {
            $extension->setupExtension(null, $parameters, null);
            $this->fail('expected EnumBindingException');
        } catch (EnumBindingException $e) {
            $this->assertSame(EnumBindingReason::EnumSpecBasePathOrphaned, $e->reason);
            $this->assertNull($e->enumFqcn);
            $this->assertNull($e->specPath);
            $this->assertStringContainsString('empty', $e->getMessage());
        }

        $this->assertStringContainsString('FATAL', $this->readStderr());
    }

    #[Test]
    public function bootstrap_fatal_when_enum_spec_base_path_set_without_spec_base_path(): void
    {
        // C1 from review: a typo in spec_base_path that leaves
        // enum_spec_base_path orphaned must FATAL instead of silently
        // dropping the parameter.
        $extension = new OpenApiCoverageExtension();
        $parameters = ParameterCollection::fromArray([
            'enum_spec_base_path' => '/some/path/openapi',
        ]);

        try {
            $extension->setupExtension(null, $parameters, null);
            $this->fail('expected EnumBindingException');
        } catch (EnumBindingException $e) {
            $this->assertSame(EnumBindingReason::EnumSpecBasePathOrphaned, $e->reason);
            $this->assertStringContainsString('spec_base_path is not', $e->getMessage());
        }

        $this->assertStringContainsString('FATAL', $this->readStderr());
    }

    #[Test]
    public function bootstrap_fatal_writes_block_to_github_step_summary_for_orphaned_enum_spec_base_path(): void
    {
        // FATAL diagnostics for spec misconfiguration already land in
        // GITHUB_STEP_SUMMARY (issue #134); the new orphaned-parameter
        // diagnostic must follow the same convention so reviewers see it
        // in the PR summary, not buried in CI logs.
        $tmp = tempnam(sys_get_temp_dir(), 'openapi-summary-');
        if ($tmp === false) {
            $this->fail('Could not create temp file for GITHUB_STEP_SUMMARY');
        }
        $this->githubSummaryTmp = $tmp;

        $extension = new OpenApiCoverageExtension();
        $parameters = ParameterCollection::fromArray([
            'enum_spec_base_path' => '/some/path/openapi',
        ]);

        try {
            $extension->setupExtension(null, $parameters, $tmp);
            $this->fail('expected EnumBindingException');
        } catch (EnumBindingException) {
            // Expected — block must still be written before re-throw.
        }

        $contents = (string) file_get_contents($tmp);
        $this->assertStringContainsString('FATAL OpenAPI enum drift', $contents);
        $this->assertStringContainsString('spec_base_path is not', $contents);
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
        $parameters = ParameterCollection::fromArray([
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
        $parameters = ParameterCollection::fromArray([
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
        $parameters = ParameterCollection::fromArray([
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
        $parameters = ParameterCollection::fromArray([
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
        $parameters = ParameterCollection::fromArray([
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
        $parameters = ParameterCollection::fromArray([
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
    public function bootstrap_warns_on_out_of_range_min_endpoint_coverage_when_not_strict(): void
    {
        // Warn-only (default) tolerates misconfiguration: surface it but
        // don't break a CI that hasn't opted into the gate yet.
        $extension = new OpenApiCoverageExtension();
        $parameters = ParameterCollection::fromArray([
            'spec_base_path' => __DIR__ . '/../../fixtures/specs',
            'specs' => 'refs-valid',
            'min_endpoint_coverage' => '150',
        ]);

        $extension->setupExtension(null, $parameters, null);

        $stderr = $this->readStderr();
        $this->assertStringContainsString('WARNING', $stderr);
        $this->assertStringContainsString('min_endpoint_coverage', $stderr);
    }

    #[Test]
    public function bootstrap_warns_on_non_numeric_min_response_coverage_when_not_strict(): void
    {
        $extension = new OpenApiCoverageExtension();
        $parameters = ParameterCollection::fromArray([
            'spec_base_path' => __DIR__ . '/../../fixtures/specs',
            'specs' => 'refs-valid',
            'min_response_coverage' => 'eighty',
        ]);

        $extension->setupExtension(null, $parameters, null);

        $stderr = $this->readStderr();
        $this->assertStringContainsString('WARNING', $stderr);
        $this->assertStringContainsString('min_response_coverage', $stderr);
    }

    #[Test]
    public function bootstrap_throws_on_out_of_range_threshold_when_strict(): void
    {
        // C1: strict=true must treat a typo'd threshold as a configuration
        // error, not warn-and-skip. The exception is caught by bootstrap()
        // and translated into exit(1) — symmetric with how stale `specs=`
        // entries fail the run after issue #134.
        $extension = new OpenApiCoverageExtension();
        $parameters = ParameterCollection::fromArray([
            'spec_base_path' => __DIR__ . '/../../fixtures/specs',
            'specs' => 'refs-valid',
            'min_endpoint_coverage' => '150',
            'min_coverage_strict' => 'true',
        ]);

        $this->expectException(InvalidThresholdConfigurationException::class);

        try {
            $extension->setupExtension(null, $parameters, null);
        } finally {
            $this->assertStringContainsString('FATAL', $this->readStderr());
            $this->assertStringContainsString('min_endpoint_coverage', $this->readStderr());
        }
    }

    #[Test]
    public function bootstrap_throws_on_non_numeric_threshold_when_strict(): void
    {
        $extension = new OpenApiCoverageExtension();
        $parameters = ParameterCollection::fromArray([
            'spec_base_path' => __DIR__ . '/../../fixtures/specs',
            'specs' => 'refs-valid',
            'min_response_coverage' => 'eighty',
            'min_coverage_strict' => 'true',
        ]);

        $this->expectException(InvalidThresholdConfigurationException::class);

        try {
            $extension->setupExtension(null, $parameters, null);
        } finally {
            $this->assertStringContainsString('FATAL', $this->readStderr());
        }
    }

    #[Test]
    public function bootstrap_treats_empty_strict_value_as_enabled(): void
    {
        // I1: `<parameter name="min_coverage_strict" />` (no value) was a
        // contradiction — the docstring promised "set = strict on" but the
        // code treated `''` as falsy. Pin the docstring's intent.
        $extension = new OpenApiCoverageExtension();
        $parameters = ParameterCollection::fromArray([
            'spec_base_path' => __DIR__ . '/../../fixtures/specs',
            'specs' => 'refs-valid',
            'min_endpoint_coverage' => '150', // out of range
            'min_coverage_strict' => '',      // shorthand for "true"
        ]);

        // Empty strict value must enable strict mode → C1 FATAL on the bad
        // threshold below.
        $this->expectException(InvalidThresholdConfigurationException::class);

        $extension->setupExtension(null, $parameters, null);
    }

    #[Test]
    public function bootstrap_accepts_well_formed_threshold_parameters(): void
    {
        $extension = new OpenApiCoverageExtension();
        $parameters = ParameterCollection::fromArray([
            'spec_base_path' => __DIR__ . '/../../fixtures/specs',
            'specs' => 'refs-valid',
            'min_endpoint_coverage' => '80',
            'min_response_coverage' => '70.5',
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
        $parameters = ParameterCollection::fromArray([
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
        $parameters = ParameterCollection::fromArray([
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
        $parameters = ParameterCollection::fromArray([
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
            $this->assertStringContainsString('enum_drift_scan_namespaces', $stderr);
        }
    }

    #[Test]
    public function bootstrap_fatals_on_drift_when_strict(): void
    {
        $extension = new OpenApiCoverageExtension();
        $parameters = ParameterCollection::fromArray([
            'spec_base_path' => __DIR__ . '/../../fixtures/specs',
            'specs' => 'refs-valid',
            'enum_drift_enabled' => 'true',
            'enum_drift_scan_namespaces' => 'Studio\\OpenApiContractTesting\\Tests\\Unit\\PHPUnit\\Fixture\\EnumDrift\\Drifting\\',
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
        $parameters = ParameterCollection::fromArray([
            'spec_base_path' => __DIR__ . '/../../fixtures/specs',
            'specs' => 'refs-valid',
            'enum_drift_enabled' => 'true',
            'enum_drift_scan_namespaces' => 'Studio\\OpenApiContractTesting\\Tests\\Unit\\PHPUnit\\Fixture\\EnumDrift\\Drifting\\',
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
    public function bootstrap_succeeds_when_no_drift(): void
    {
        $extension = new OpenApiCoverageExtension();
        $parameters = ParameterCollection::fromArray([
            'spec_base_path' => __DIR__ . '/../../fixtures/specs',
            'specs' => 'refs-valid',
            'enum_drift_enabled' => 'true',
            'enum_drift_scan_namespaces' => 'Studio\\OpenApiContractTesting\\Tests\\Unit\\PHPUnit\\Fixture\\EnumDrift\\Clean\\',
        ]);

        $extension->setupExtension(null, $parameters, null);

        $this->assertSame('', $this->readStderr());
    }

    #[Test]
    public function bootstrap_fatals_on_misconfigured_binding_even_when_lenient(): void
    {
        $extension = new OpenApiCoverageExtension();
        $parameters = ParameterCollection::fromArray([
            'spec_base_path' => __DIR__ . '/../../fixtures/specs',
            'specs' => 'refs-valid',
            'enum_drift_enabled' => 'true',
            'enum_drift_scan_namespaces' => 'Studio\\OpenApiContractTesting\\Tests\\Unit\\PHPUnit\\Fixture\\EnumDrift\\Misconfigured\\',
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
    public function bootstrap_treats_empty_enum_drift_enabled_as_enabled(): void
    {
        $extension = new OpenApiCoverageExtension();
        $parameters = ParameterCollection::fromArray([
            'spec_base_path' => __DIR__ . '/../../fixtures/specs',
            'specs' => 'refs-valid',
            'enum_drift_enabled' => '', // shorthand for true (parity with min_coverage_strict)
        ]);

        // Empty `enum_drift_enabled` enables the check, so the empty
        // namespace list trips the FATAL guard.
        try {
            $extension->setupExtension(null, $parameters, null);
            $this->fail('Expected EnumBindingException');
        } catch (EnumBindingException $e) {
            $this->assertSame(EnumBindingReason::NoNamespacesConfigured, $e->reason);
        }
    }

    #[Test]
    public function bootstrap_parses_namespaces_csv_with_whitespace(): void
    {
        $extension = new OpenApiCoverageExtension();
        $parameters = ParameterCollection::fromArray([
            'spec_base_path' => __DIR__ . '/../../fixtures/specs',
            'specs' => 'refs-valid',
            'enum_drift_enabled' => 'true',
            'enum_drift_scan_namespaces' =>
                ' Studio\\OpenApiContractTesting\\Tests\\Unit\\PHPUnit\\Fixture\\EnumDrift\\Clean\\ '
                . ', '
                . ' Studio\\OpenApiContractTesting\\Tests\\Unit\\PHPUnit\\Fixture\\EnumDrift\\Clean\\ ',
        ]);

        $extension->setupExtension(null, $parameters, null);

        $this->assertSame('', $this->readStderr());
    }

    #[Test]
    public function bootstrap_emits_note_when_no_attributed_enums_found(): void
    {
        // Resolvable namespace prefix that contains enums but none with the
        // attribute — typo'd `enum_drift_scan_namespaces` lands here too.
        // The NOTE must surface to stderr so a typo is observable, but the
        // run must continue (codebases mid-migration have this state too).
        $extension = new OpenApiCoverageExtension();
        $parameters = ParameterCollection::fromArray([
            'spec_base_path' => __DIR__ . '/../../fixtures/specs',
            'specs' => 'refs-valid',
            'enum_drift_enabled' => 'true',
            // Resolvable PSR-4 root that contains zero attributed enums.
            'enum_drift_scan_namespaces' =>
                'Studio\\OpenApiContractTesting\\Tests\\Unit\\Internal\\Fixture\\NotAnEnumNs\\',
        ]);

        // Resolvable but empty PSR-4 root — use a sub-namespace under tests/
        // that doesn't exist so prefixHasResolvableRoot still returns true
        // via the longest-match path, but the directory walk yields nothing.
        try {
            $extension->setupExtension(null, $parameters, null);
            // Fall-through path: NOTE was emitted, run continues.
            $stderr = $this->readStderr();
            $this->assertStringContainsString('[OpenAPI Enum Drift] NOTE', $stderr);
            $this->assertStringContainsString('zero #[BoundToOpenApiEnum] enums', $stderr);
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
    public function bootstrap_appends_fatal_block_to_step_summary_for_enum_drift(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'openapi-enum-summary-');
        if ($tmp === false) {
            $this->fail('Could not create temp file for GITHUB_STEP_SUMMARY');
        }
        $this->githubSummaryTmp = $tmp;

        $extension = new OpenApiCoverageExtension();
        $parameters = ParameterCollection::fromArray([
            'spec_base_path' => __DIR__ . '/../../fixtures/specs',
            'specs' => 'refs-valid',
            'enum_drift_enabled' => 'true',
            'enum_drift_scan_namespaces' => 'Studio\\OpenApiContractTesting\\Tests\\Unit\\PHPUnit\\Fixture\\EnumDrift\\Drifting\\',
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
    public function bootstrap_appends_warning_block_to_step_summary_for_lenient_drift(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'openapi-enum-summary-');
        if ($tmp === false) {
            $this->fail('Could not create temp file for GITHUB_STEP_SUMMARY');
        }
        $this->githubSummaryTmp = $tmp;

        $extension = new OpenApiCoverageExtension();
        $parameters = ParameterCollection::fromArray([
            'spec_base_path' => __DIR__ . '/../../fixtures/specs',
            'specs' => 'refs-valid',
            'enum_drift_enabled' => 'true',
            'enum_drift_scan_namespaces' => 'Studio\\OpenApiContractTesting\\Tests\\Unit\\PHPUnit\\Fixture\\EnumDrift\\Drifting\\',
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
        $parameters = ParameterCollection::fromArray([
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
        $parameters = ParameterCollection::fromArray([
            'spec_base_path' => __DIR__ . '/../../fixtures/specs',
            'specs' => 'refs-valid',
            'enum_drift_enabled' => 'true',
            'enum_drift_scan_namespaces' =>
                'Studio\\OpenApiContractTesting\\Tests\\Unit\\PHPUnit\\Fixture\\EnumDrift\\Clean\\',
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
    public function bootstrap_fatals_on_drift_when_fail_on_drift_omitted(): void
    {
        // Default for `enum_drift_fail_on_drift` is `true`. Pin the default
        // by simply omitting the parameter and asserting drift FATALs.
        $extension = new OpenApiCoverageExtension();
        $parameters = ParameterCollection::fromArray([
            'spec_base_path' => __DIR__ . '/../../fixtures/specs',
            'specs' => 'refs-valid',
            'enum_drift_enabled' => 'true',
            'enum_drift_scan_namespaces' => 'Studio\\OpenApiContractTesting\\Tests\\Unit\\PHPUnit\\Fixture\\EnumDrift\\Drifting\\',
            // enum_drift_fail_on_drift intentionally omitted
        ]);

        $this->expectException(EnumDriftException::class);

        $extension->setupExtension(null, $parameters, null);
    }

    #[Test]
    public function bootstrap_fatal_when_junit_output_is_empty(): void
    {
        // <parameter name="junit_output" value=""/> would otherwise coerce to
        // getcwd() and silently overwrite a directory listing as a file.
        // Mirror the enum_spec_base_path policy: empty → FATAL.
        $extension = new OpenApiCoverageExtension();
        $parameters = ParameterCollection::fromArray([
            'spec_base_path' => __DIR__ . '/../../fixtures/specs',
            'specs' => 'refs-valid',
            'junit_output' => '',
        ]);

        try {
            $extension->setupExtension(null, $parameters, null);
            $this->fail('expected InvalidCoverageOutputPathException');
        } catch (InvalidCoverageOutputPathException $e) {
            $this->assertSame('junit_output', $e->parameterName);
            $this->assertStringContainsString('empty', $e->getMessage());
        }

        $this->assertStringContainsString('FATAL', $this->readStderr());
    }

    #[Test]
    public function bootstrap_fatal_when_json_output_is_empty(): void
    {
        // Reuses resolveOutputPathParameter() helper validated for junit_output;
        // pin the parameter name so a future helper rewrite that drops
        // json_output wiring is caught.
        $extension = new OpenApiCoverageExtension();
        $parameters = ParameterCollection::fromArray([
            'spec_base_path' => __DIR__ . '/../../fixtures/specs',
            'specs' => 'refs-valid',
            'json_output' => '',
        ]);

        try {
            $extension->setupExtension(null, $parameters, null);
            $this->fail('expected InvalidCoverageOutputPathException');
        } catch (InvalidCoverageOutputPathException $e) {
            $this->assertSame('json_output', $e->parameterName);
            $this->assertStringContainsString('empty', $e->getMessage());
        }

        $this->assertStringContainsString('FATAL', $this->readStderr());
    }

    #[Test]
    public function bootstrap_fatal_when_junit_output_parent_dir_not_writable(): void
    {
        // Surface the misconfiguration at bootstrap rather than as a runtime
        // file_put_contents() WARN after every test ran. /proc/0 does not
        // exist on macOS and is unwritable on Linux — fails closed either way.
        $extension = new OpenApiCoverageExtension();
        $parameters = ParameterCollection::fromArray([
            'spec_base_path' => __DIR__ . '/../../fixtures/specs',
            'specs' => 'refs-valid',
            'junit_output' => '/proc/0/nonexistent/coverage.junit.xml',
        ]);

        try {
            $extension->setupExtension(null, $parameters, null);
            $this->fail('expected InvalidCoverageOutputPathException');
        } catch (InvalidCoverageOutputPathException $e) {
            $this->assertSame('junit_output', $e->parameterName);
            $this->assertStringContainsString('not writable', $e->getMessage());
        }

        $this->assertStringContainsString('FATAL', $this->readStderr());
    }

    #[Test]
    public function bootstrap_fatal_when_html_output_is_empty(): void
    {
        // Reuses resolveOutputPathParameter() helper validated for junit_output;
        // pin the parameter name so a future helper rewrite that drops
        // html_output wiring is caught.
        $extension = new OpenApiCoverageExtension();
        $parameters = ParameterCollection::fromArray([
            'spec_base_path' => __DIR__ . '/../../fixtures/specs',
            'specs' => 'refs-valid',
            'html_output' => '',
        ]);

        try {
            $extension->setupExtension(null, $parameters, null);
            $this->fail('expected InvalidCoverageOutputPathException');
        } catch (InvalidCoverageOutputPathException $e) {
            $this->assertSame('html_output', $e->parameterName);
            $this->assertStringContainsString('empty', $e->getMessage());
        }

        $this->assertStringContainsString('FATAL', $this->readStderr());
    }

    #[Test]
    public function bootstrap_fatal_when_html_output_parent_dir_not_writable(): void
    {
        // Parity with the junit_output / json_output checks — the shared
        // resolveOutputPathParameter() helper should fail closed for
        // html_output too.
        $extension = new OpenApiCoverageExtension();
        $parameters = ParameterCollection::fromArray([
            'spec_base_path' => __DIR__ . '/../../fixtures/specs',
            'specs' => 'refs-valid',
            'html_output' => '/proc/0/nonexistent/coverage.html',
        ]);

        try {
            $extension->setupExtension(null, $parameters, null);
            $this->fail('expected InvalidCoverageOutputPathException');
        } catch (InvalidCoverageOutputPathException $e) {
            $this->assertSame('html_output', $e->parameterName);
            $this->assertStringContainsString('not writable', $e->getMessage());
        }

        $this->assertStringContainsString('FATAL', $this->readStderr());
    }

    #[Test]
    public function bootstrap_fatal_when_json_output_parent_dir_not_writable(): void
    {
        // Parity with the junit_output check — the shared
        // resolveOutputPathParameter() helper should fail closed for
        // json_output too. Guards against a future change to the helper that
        // accidentally pins it to one parameter name.
        $extension = new OpenApiCoverageExtension();
        $parameters = ParameterCollection::fromArray([
            'spec_base_path' => __DIR__ . '/../../fixtures/specs',
            'specs' => 'refs-valid',
            'json_output' => '/proc/0/nonexistent/coverage.json',
        ]);

        try {
            $extension->setupExtension(null, $parameters, null);
            $this->fail('expected InvalidCoverageOutputPathException');
        } catch (InvalidCoverageOutputPathException $e) {
            $this->assertSame('json_output', $e->parameterName);
            $this->assertStringContainsString('not writable', $e->getMessage());
        }

        $this->assertStringContainsString('FATAL', $this->readStderr());
    }

    #[Test]
    public function strict_required_unknown_value_throws_invalid_config(): void
    {
        $extension = new OpenApiCoverageExtension();
        $parameters = ParameterCollection::fromArray([
            'spec_base_path' => __DIR__ . '/../../fixtures/specs',
            'specs' => 'refs-valid',
            'strict_required' => 'enforce',
        ]);

        try {
            $extension->setupExtension(null, $parameters, null);
            $this->fail('expected InvalidStrictRequiredConfigurationException');
        } catch (InvalidStrictRequiredConfigurationException $e) {
            $this->assertStringContainsString('strict_required=enforce', $e->getMessage());
        }

        $this->assertStringContainsString('[OpenAPI Strict Required] FATAL', $this->readStderr());
    }

    #[Test]
    public function strict_required_off_does_not_throw(): void
    {
        StrictRequiredTracker::reset();

        $extension = new OpenApiCoverageExtension();
        $parameters = ParameterCollection::fromArray([
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
        $parameters = ParameterCollection::fromArray([
            'spec_base_path' => __DIR__ . '/../../fixtures/specs',
            'specs' => 'refs-valid',
            'strict_required' => 'warn',
        ]);

        $extension->setupExtension(null, $parameters, null);

        $this->assertSame([], StrictRequiredTracker::getObservations('refs-valid'));
    }

    #[Test]
    public function strict_required_per_call_warn_configures_checker_at_bootstrap(): void
    {
        StrictRequiredPerCallChecker::reset();

        $extension = new OpenApiCoverageExtension();
        $parameters = ParameterCollection::fromArray([
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
        $parameters = ParameterCollection::fromArray([
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
    public function strict_required_per_call_unknown_value_throws_invalid_config(): void
    {
        StrictRequiredPerCallChecker::reset();

        $extension = new OpenApiCoverageExtension();
        $parameters = ParameterCollection::fromArray([
            'spec_base_path' => __DIR__ . '/../../fixtures/specs',
            'specs' => 'refs-valid',
            'strict_required_per_call' => 'enforce',
        ]);

        try {
            $extension->setupExtension(null, $parameters, null);
            $this->fail('expected InvalidStrictRequiredConfigurationException');
        } catch (InvalidStrictRequiredConfigurationException $e) {
            $this->assertStringContainsString('strict_required_per_call=enforce', $e->getMessage());
        } finally {
            StrictRequiredPerCallChecker::reset();
        }

        $this->assertStringContainsString('[OpenAPI Strict Required per-call] FATAL', $this->readStderr());
    }

    #[Test]
    public function strict_required_per_call_rejects_fail_value(): void
    {
        // Per-call is intentionally warn-only — the FATAL message must use
        // the per-call prefix so users grepping for `[OpenAPI Strict
        // Required per-call]` find it, and the accepted-list must NOT
        // include `fail`.
        StrictRequiredPerCallChecker::reset();

        $extension = new OpenApiCoverageExtension();
        $parameters = ParameterCollection::fromArray([
            'spec_base_path' => __DIR__ . '/../../fixtures/specs',
            'specs' => 'refs-valid',
            'strict_required_per_call' => 'fail',
        ]);

        try {
            $extension->setupExtension(null, $parameters, null);
            $this->fail('expected InvalidStrictRequiredConfigurationException');
        } catch (InvalidStrictRequiredConfigurationException $e) {
            $this->assertStringContainsString('strict_required_per_call=fail', $e->getMessage());
        } finally {
            StrictRequiredPerCallChecker::reset();
        }

        $stderr = $this->readStderr();
        $this->assertStringContainsString('[OpenAPI Strict Required per-call] FATAL', $stderr);
        $this->assertStringContainsString('Accepted: off, warn', $stderr);
    }

    #[Test]
    public function strict_required_per_call_invalid_value_appends_fatal_block_to_step_summary(): void
    {
        // Issue #228 review: a typoed strict_required_per_call must emit
        // its FATAL block to GITHUB_STEP_SUMMARY just like every other
        // bootstrap-level FATAL. Without this pin, a future refactor that
        // drops the appendGithubStepSummaryStrictRequiredBlock() call would
        // remove the most-visible CI signal for per-call misconfiguration —
        // the failure would only surface in scrolling stderr logs.
        StrictRequiredPerCallChecker::reset();
        $tmp = tempnam(sys_get_temp_dir(), 'openapi-summary-');
        if ($tmp === false) {
            $this->fail('Could not create temp file for GITHUB_STEP_SUMMARY');
        }
        $this->githubSummaryTmp = $tmp;

        $extension = new OpenApiCoverageExtension();
        $parameters = ParameterCollection::fromArray([
            'spec_base_path' => __DIR__ . '/../../fixtures/specs',
            'specs' => 'refs-valid',
            'strict_required_per_call' => 'enforce',
        ]);

        try {
            $extension->setupExtension(null, $parameters, $tmp);
            $this->fail('expected InvalidStrictRequiredConfigurationException');
        } catch (InvalidStrictRequiredConfigurationException) {
            // expected
        } finally {
            StrictRequiredPerCallChecker::reset();
        }

        $contents = (string) file_get_contents($tmp);
        $this->assertStringContainsString('FATAL OpenAPI strict_required drift', $contents);
        $this->assertStringContainsString('strict_required_per_call=enforce', $contents);
    }

    #[Test]
    public function strict_required_per_call_fail_value_appends_fatal_block_to_step_summary(): void
    {
        // Parallel pin to the unknown-value case above — `fail` is the
        // single most likely typo (users coming from the run-level docs
        // assume it works) and the FATAL block must surface the design
        // rationale on the GitHub side too.
        StrictRequiredPerCallChecker::reset();
        $tmp = tempnam(sys_get_temp_dir(), 'openapi-summary-');
        if ($tmp === false) {
            $this->fail('Could not create temp file for GITHUB_STEP_SUMMARY');
        }
        $this->githubSummaryTmp = $tmp;

        $extension = new OpenApiCoverageExtension();
        $parameters = ParameterCollection::fromArray([
            'spec_base_path' => __DIR__ . '/../../fixtures/specs',
            'specs' => 'refs-valid',
            'strict_required_per_call' => 'fail',
        ]);

        try {
            $extension->setupExtension(null, $parameters, $tmp);
            $this->fail('expected InvalidStrictRequiredConfigurationException');
        } catch (InvalidStrictRequiredConfigurationException) {
            // expected
        } finally {
            StrictRequiredPerCallChecker::reset();
        }

        $contents = (string) file_get_contents($tmp);
        $this->assertStringContainsString('FATAL OpenAPI strict_required drift', $contents);
        $this->assertStringContainsString('strict_required_per_call=fail', $contents);
    }

    #[Test]
    public function strict_required_per_call_and_run_level_can_coexist(): void
    {
        StrictRequiredPerCallChecker::reset();

        $extension = new OpenApiCoverageExtension();
        $parameters = ParameterCollection::fromArray([
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

    private function readStderr(): string
    {
        if ($this->stderrBuffer === null) {
            return '';
        }
        rewind($this->stderrBuffer);

        return (string) stream_get_contents($this->stderrBuffer);
    }
}
