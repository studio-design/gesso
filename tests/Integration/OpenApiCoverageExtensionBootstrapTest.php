<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function escapeshellarg;
use function fclose;
use function file_put_contents;
use function is_resource;
use function proc_close;
use function proc_open;
use function realpath;
use function sprintf;
use function stream_get_contents;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

/**
 * Exercises the real `bootstrap()` -> `exit(1)` path by spawning a child
 * PHPUnit process. The unit tests cover `setupExtension()` directly, which
 * bypasses the wrapper; this test pins the observable contract — broken
 * spec → non-zero PHPUnit exit — that the PR exists to guarantee.
 */
class OpenApiCoverageExtensionBootstrapTest extends TestCase
{
    private string $repoRoot;
    private ?string $configPath = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repoRoot = realpath(__DIR__ . '/../..') ?: __DIR__ . '/../..';
    }

    protected function tearDown(): void
    {
        if ($this->configPath !== null) {
            @unlink($this->configPath);
            $this->configPath = null;
        }
        parent::tearDown();
    }

    #[Test]
    public function phpunit_exits_non_zero_when_registered_spec_has_unresolvable_ref(): void
    {
        // Filter matches no real tests so we observe the bootstrap-only path:
        // if the suite exited 0 here, the hard-fail contract is broken.
        [$exit, $stderr] = $this->runPhpunit('refs-unresolvable', '--filter=DoesNotMatchAnyTest');

        $this->assertNotSame(0, $exit, "Expected non-zero exit; stderr was:\n" . $stderr);
        $this->assertStringContainsString('FATAL', $stderr);
        $this->assertStringContainsString('refs-unresolvable', $stderr);
    }

    #[Test]
    public function phpunit_exits_non_zero_when_registered_spec_has_unsupported_version(): void
    {
        [$exit, $stderr] = $this->runPhpunit('unsupported-version', '--filter=DoesNotMatchAnyTest');

        $this->assertNotSame(0, $exit, "Expected non-zero exit; stderr was:\n" . $stderr);
        $this->assertStringContainsString('FATAL', $stderr);
        $this->assertStringContainsString("Unsupported OpenAPI version: '3.3.0' (string)", $stderr);
        $this->assertStringContainsString('3.0.x, 3.1.x, or 3.2.x', $stderr);
    }

    #[Test]
    public function phpunit_exits_non_zero_when_registered_spec_file_is_missing(): void
    {
        // issue #134 contract pinned end-to-end: the unit test covers the
        // throw, this one verifies PHPUnit's bootstrapper actually exits
        // non-zero rather than demoting to a warning. The `Action:` assert
        // guarantees the remediation hint survives the subprocess boundary
        // (where PHP could otherwise truncate stderr on an early exit).
        [$exit, $stderr] = $this->runPhpunit('does-not-exist', '--filter=DoesNotMatchAnyTest');

        $this->assertNotSame(0, $exit, "Expected non-zero exit; stderr was:\n" . $stderr);
        $this->assertStringContainsString('FATAL', $stderr);
        $this->assertStringContainsString('does-not-exist', $stderr);
        $this->assertStringContainsString('Action:', $stderr);
    }

    #[Test]
    public function default_testsuite_as_full_warns_when_no_default_configured(): void
    {
        // Issue #236: opting in without declaring `defaultTestSuite` makes
        // the flag silently inert otherwise — the partial detection still
        // suppresses `strict_required` and coverage outputs, and the user
        // can't tell why their gate isn't firing. Bootstrap must surface
        // the misconfiguration. This also pins the configuration→bootstrap
        // wiring: the WARN only fires if `default_testsuite_as_full=true`
        // is read off the ParameterCollection AND `hasDefaultTestSuite()`
        // is consulted on the Configuration in the same path.
        $output = $this->runPhpunitWithDefaultTestSuite(
            defaultTestSuite: null,
            optInValue: 'true',
            outputFile: null,
        );

        $this->assertStringContainsString('default_testsuite_as_full=true', $output);
        $this->assertStringContainsString('does not declare', $output);
    }

    #[Test]
    public function default_testsuite_as_full_warns_when_default_is_empty_string(): void
    {
        // `<phpunit defaultTestSuite="">` is valid XML but makes the
        // opt-in inert (no possible match). PHPUnit 13.2 normalises the empty
        // attribute to "not declared", while older supported versions expose
        // the empty string. Both paths must surface the same inert-opt-in WARN.
        $output = $this->runPhpunitWithDefaultTestSuite(
            defaultTestSuite: '',
            optInValue: 'true',
            outputFile: null,
        );

        $this->assertStringContainsString('default_testsuite_as_full=true', $output);
        $this->assertStringContainsString('will be inert', $output);
    }

    #[Test]
    public function default_testsuite_as_full_does_not_warn_for_matching_default_testsuite(): void
    {
        // Positive case: opt-in + matching defaultTestSuite is a valid
        // configuration and must not emit either of the inert-WARN lines.
        // Pins that the WARN paths are gated on the misconfiguration, not
        // on the opt-in itself. The subscriber's persistent-write WARN
        // ("Skipping output_file ...") is exercised by
        // `CoverageReportSubscriberPartialRunTest`; reaching it from
        // bootstrap requires the test to actually record coverage, which
        // is out of scope for this integration check.
        $output = $this->runPhpunitWithDefaultTestSuite(
            defaultTestSuite: 'Unit',
            optInValue: 'true',
            outputFile: null,
        );

        $this->assertStringNotContainsString('default_testsuite_as_full=true but', $output);
    }

    #[Test]
    public function default_testsuite_as_full_warn_does_not_fire_when_opt_in_is_absent(): void
    {
        // Negative case: when the user hasn't opted in, the WARN paths
        // must stay silent even if the rest of the configuration would
        // otherwise trip them (here: matching defaultTestSuite, but no
        // opt-in). Pins that the warn gate is bound to the opt-in.
        $output = $this->runPhpunitWithDefaultTestSuite(
            defaultTestSuite: 'Unit',
            optInValue: null,
            outputFile: null,
        );

        $this->assertStringNotContainsString('default_testsuite_as_full=true', $output);
    }

    /**
     * @return array{0: int, 1: string} [exit code, combined stderr]
     */
    private function runPhpunit(string $specsParam, string $filterArg): array
    {
        $xml = sprintf(
            '<?xml version="1.0" encoding="UTF-8"?>'
            . '<phpunit bootstrap="%s/vendor/autoload.php" cacheDirectory="%s/.phpunit.cache" colors="false">'
            . '<testsuites><testsuite name="Unit"><directory>%s/tests/Unit</directory></testsuite></testsuites>'
            . '<extensions><bootstrap class="Studio\OpenApiContractTesting\PHPUnit\OpenApiCoverageExtension">'
            . '<parameter name="spec_base_path" value="%s/tests/fixtures/specs"/>'
            . '<parameter name="specs" value="%s"/>'
            . '</bootstrap></extensions>'
            . '</phpunit>',
            $this->repoRoot,
            $this->repoRoot,
            $this->repoRoot,
            $this->repoRoot,
            $specsParam,
        );

        $tmp = tempnam(sys_get_temp_dir(), 'openapi-ext-integration-') ?: null;
        if ($tmp === null) {
            $this->fail('Could not create temp phpunit config');
        }
        $this->configPath = $tmp;
        file_put_contents($tmp, $xml);

        $cmd = sprintf(
            '%s/vendor/bin/phpunit -c %s %s',
            escapeshellarg($this->repoRoot),
            escapeshellarg($tmp),
            escapeshellarg($filterArg),
        );

        $descriptors = [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = proc_open($cmd, $descriptors, $pipes, $this->repoRoot);
        if (!is_resource($process)) {
            $this->fail('Could not spawn phpunit subprocess');
        }

        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        foreach ($pipes as $pipe) {
            fclose($pipe);
        }
        $exit = proc_close($process);

        // Both streams may carry the FATAL/WARNING line depending on where
        // PHP flushes; the caller only cares about the combined text.
        return [$exit, $stdout . $stderr];
    }

    /**
     * Run a subprocess phpunit with a custom `defaultTestSuite` attribute
     * and (optionally) the `default_testsuite_as_full` opt-in. Targets
     * `tests/Unit/Internal/PartialRunDecisionTest.php` only so the
     * subscriber's `ExecutionFinished` hook fires (a `--filter` to no
     * matches would early-exit before that) while keeping the run cheap.
     * The testsuite name `Unit` matches the `defaultTestSuite` attribute
     * we set on the synthetic `<phpunit>`, which is the contract we want
     * to pin.
     */
    private function runPhpunitWithDefaultTestSuite(
        ?string $defaultTestSuite,
        ?string $optInValue,
        ?string $outputFile,
    ): string {
        $defaultAttr = $defaultTestSuite === null
            ? ''
            : sprintf(' defaultTestSuite="%s"', $defaultTestSuite);

        $optInParam = $optInValue === null
            ? ''
            : sprintf('<parameter name="default_testsuite_as_full" value="%s"/>', $optInValue);

        $outputFileParam = $outputFile === null
            ? ''
            : sprintf('<parameter name="output_file" value="%s"/>', $outputFile);

        $xml = sprintf(
            '<?xml version="1.0" encoding="UTF-8"?>'
            . '<phpunit bootstrap="%s/vendor/autoload.php" cacheDirectory="%s/.phpunit.cache" '
            . 'colors="false" failOnEmptyTestSuite="false"%s>'
            . '<testsuites><testsuite name="Unit"><file>%s/tests/Unit/Internal/PartialRunDecisionTest.php</file></testsuite></testsuites>'
            . '<extensions><bootstrap class="Studio\OpenApiContractTesting\PHPUnit\OpenApiCoverageExtension">'
            . '<parameter name="spec_base_path" value="%s/tests/fixtures/specs"/>'
            . '<parameter name="specs" value="composition"/>'
            . '%s%s'
            . '</bootstrap></extensions>'
            . '</phpunit>',
            $this->repoRoot,
            $this->repoRoot,
            $defaultAttr,
            $this->repoRoot,
            $this->repoRoot,
            $optInParam,
            $outputFileParam,
        );

        $tmp = tempnam(sys_get_temp_dir(), 'openapi-ext-integration-') ?: null;
        if ($tmp === null) {
            $this->fail('Could not create temp phpunit config');
        }
        $this->configPath = $tmp;
        file_put_contents($tmp, $xml);

        $cmd = sprintf(
            '%s/vendor/bin/phpunit -c %s',
            escapeshellarg($this->repoRoot),
            escapeshellarg($tmp),
        );

        $descriptors = [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = proc_open($cmd, $descriptors, $pipes, $this->repoRoot);
        if (!is_resource($process)) {
            $this->fail('Could not spawn phpunit subprocess');
        }

        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        foreach ($pipes as $pipe) {
            fclose($pipe);
        }
        proc_close($process);

        return $stdout . $stderr;
    }
}
