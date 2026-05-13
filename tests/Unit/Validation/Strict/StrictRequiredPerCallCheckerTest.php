<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Tests\Unit\Validation\Strict;

use const E_USER_WARNING;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\OpenApiContractTesting\PHPUnit\OpenApiCoverageExtension;
use Studio\OpenApiContractTesting\Spec\OpenApiSpecLoader;
use Studio\OpenApiContractTesting\Validation\Strict\StrictRequiredPerCallChecker;
use Studio\OpenApiContractTesting\Validation\Strict\StrictRequiredPerCallMode;

use function fopen;
use function restore_error_handler;
use function rewind;
use function set_error_handler;
use function stream_get_contents;
use function strpos;
use function substr_count;

final class StrictRequiredPerCallCheckerTest extends TestCase
{
    private const SPEC_BASE_PATH = __DIR__ . '/../../../fixtures/specs';
    private const SPEC_NAME = 'under-described';

    /** @var null|resource */
    private $stderrBuffer;

    protected function setUp(): void
    {
        parent::setUp();
        OpenApiSpecLoader::reset();
        OpenApiSpecLoader::configure(self::SPEC_BASE_PATH);
        StrictRequiredPerCallChecker::reset();

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
        $this->stderrBuffer = null;
        StrictRequiredPerCallChecker::reset();
        OpenApiSpecLoader::reset();
        parent::tearDown();
    }

    #[Test]
    public function default_mode_is_off(): void
    {
        $this->assertSame(StrictRequiredPerCallMode::Off, StrictRequiredPerCallChecker::mode());
    }

    #[Test]
    public function configure_persists_mode_until_reset(): void
    {
        StrictRequiredPerCallChecker::configure(StrictRequiredPerCallMode::Warn);
        $this->assertSame(StrictRequiredPerCallMode::Warn, StrictRequiredPerCallChecker::mode());

        StrictRequiredPerCallChecker::reset();
        $this->assertSame(StrictRequiredPerCallMode::Off, StrictRequiredPerCallChecker::mode());
    }

    #[Test]
    public function off_mode_does_not_emit_warning_even_with_drift(): void
    {
        // Off is the default — the spec under /signed-url declares
        // expires/signed_url/url as optional, so a warn-mode call would
        // certainly fire. Verify Off produces silence.
        $captured = $this->captureFirstWarning(static function (): void {
            StrictRequiredPerCallChecker::maybeWarn(
                self::SPEC_NAME,
                'PUT',
                '/signed-url',
                '200',
                'application/json',
                ['/' => ['expires', 'signed_url', 'url']],
            );
        });

        $this->assertNull($captured);
    }

    #[Test]
    public function warn_mode_emits_warning_with_per_call_prefix_and_missing_keys(): void
    {
        StrictRequiredPerCallChecker::configure(StrictRequiredPerCallMode::Warn);

        $captured = $this->captureFirstWarning(static function (): void {
            StrictRequiredPerCallChecker::maybeWarn(
                self::SPEC_NAME,
                'PUT',
                '/signed-url',
                '200',
                'application/json',
                ['/' => ['expires', 'signed_url', 'url']],
            );
        });

        $this->assertNotNull($captured);
        $this->assertStringContainsString('[OpenAPI Strict Required per-call] WARN:', $captured);
        $this->assertStringContainsString('PUT /signed-url', $captured);
        $this->assertStringContainsString('200', $captured);
        $this->assertStringContainsString('application/json', $captured);
        $this->assertStringContainsString('/ : expires, signed_url, url', $captured);
    }

    #[Test]
    public function warn_mode_does_not_emit_when_observed_keys_are_all_required(): void
    {
        StrictRequiredPerCallChecker::configure(StrictRequiredPerCallMode::Warn);

        $captured = $this->captureFirstWarning(static function (): void {
            // /users/{id} declares both `id` and `name` as required, so a
            // body that contains exactly those keys has zero drift.
            StrictRequiredPerCallChecker::maybeWarn(
                self::SPEC_NAME,
                'GET',
                '/users/{id}',
                '200',
                'application/json',
                ['/' => ['id', 'name']],
            );
        });

        $this->assertNull($captured);
    }

    #[Test]
    public function warn_mode_emits_only_pointers_with_drift(): void
    {
        StrictRequiredPerCallChecker::configure(StrictRequiredPerCallMode::Warn);

        // /projects/{id} requires id+name; created_at is optional. The
        // observation has all three, so only `created_at` should drift.
        $captured = $this->captureFirstWarning(static function (): void {
            StrictRequiredPerCallChecker::maybeWarn(
                self::SPEC_NAME,
                'GET',
                '/projects/{id}',
                '200',
                'application/json',
                ['/' => ['created_at', 'id', 'name']],
            );
        });

        $this->assertNotNull($captured);
        $this->assertStringContainsString('/ : created_at', $captured);
        $this->assertStringNotContainsString('id, name', $captured);
    }

    #[Test]
    public function warn_mode_reports_nested_pointer_drift(): void
    {
        StrictRequiredPerCallChecker::configure(StrictRequiredPerCallMode::Warn);

        // /teams/{id} → data.required=["name"]. Body always returns
        // created_at too — drift expected at /data.
        $captured = $this->captureFirstWarning(static function (): void {
            StrictRequiredPerCallChecker::maybeWarn(
                self::SPEC_NAME,
                'GET',
                '/teams/{id}',
                '200',
                'application/json',
                [
                    '/' => ['data', 'id'],
                    '/data' => ['created_at', 'name'],
                ],
            );
        });

        $this->assertNotNull($captured);
        $this->assertStringContainsString('/data : created_at', $captured);
    }

    #[Test]
    public function warn_mode_does_not_warn_under_disjunction_but_emits_one_shot_note(): void
    {
        StrictRequiredPerCallChecker::configure(StrictRequiredPerCallMode::Warn);

        // /either-shape root is `anyOf`. Per-call must not warn (the
        // disjunction has no AND-semantic for `required`) BUT it emits a
        // one-shot stderr NOTE so per-call-only configurations can still
        // see that the endpoint is invisible to per-call drift detection.
        $captured = $this->captureFirstWarning(static function (): void {
            StrictRequiredPerCallChecker::maybeWarn(
                self::SPEC_NAME,
                'GET',
                '/either-shape',
                '200',
                'application/json',
                ['/' => ['a']],
            );
        });

        $this->assertNull($captured);

        $stderr = $this->readStderr();
        $this->assertStringContainsString('[OpenAPI Strict Required per-call] NOTE:', $stderr);
        $this->assertStringContainsString('GET /either-shape', $stderr);
        $this->assertStringContainsString('anyOf', $stderr);
        $this->assertStringContainsString('<root>', $stderr);
    }

    #[Test]
    public function warn_mode_one_of_disjunction_also_emits_note(): void
    {
        StrictRequiredPerCallChecker::configure(StrictRequiredPerCallMode::Warn);

        // Parallel coverage to anyOf above — `/either-shape-oneof` exists
        // in the fixture specifically so a future refactor that adds
        // `oneOf` descent fails loudly here.
        $captured = $this->captureFirstWarning(static function (): void {
            StrictRequiredPerCallChecker::maybeWarn(
                self::SPEC_NAME,
                'GET',
                '/either-shape-oneof',
                '200',
                'application/json',
                ['/' => ['a']],
            );
        });

        $this->assertNull($captured);
        $stderr = $this->readStderr();
        $this->assertStringContainsString('GET /either-shape-oneof', $stderr);
        $this->assertStringContainsString('oneOf', $stderr);
    }

    #[Test]
    public function warn_mode_does_not_warn_for_unknown_endpoint_but_emits_note(): void
    {
        StrictRequiredPerCallChecker::configure(StrictRequiredPerCallMode::Warn);

        // Endpoint not in spec — per-call must not fail-loud (escalating
        // an infrastructure mismatch into a per-test warning attributes
        // the bug to the wrong test layer), but it emits a NOTE so
        // per-call-only users can detect path-matcher / asserter drift.
        $captured = $this->captureFirstWarning(static function (): void {
            StrictRequiredPerCallChecker::maybeWarn(
                self::SPEC_NAME,
                'GET',
                '/does-not-exist',
                '200',
                'application/json',
                ['/' => ['anything']],
            );
        });

        $this->assertNull($captured);

        $stderr = $this->readStderr();
        $this->assertStringContainsString('[OpenAPI Strict Required per-call] NOTE:', $stderr);
        $this->assertStringContainsString('GET /does-not-exist', $stderr);
        $this->assertStringContainsString('could not be resolved', $stderr);
    }

    #[Test]
    public function warn_mode_does_not_warn_when_spec_is_malformed_json_but_emits_note(): void
    {
        // The catch list covers both SpecFileNotFoundException AND
        // InvalidOpenApiSpecException — a future refactor that narrowed
        // the catch to only the missing-file case would escalate a
        // malformed-spec exception into a per-test warning, which is the
        // exact misattribution the silent-skip is documented to prevent.
        // The `malformed-json` fixture throws InvalidOpenApiSpecException
        // on load.
        StrictRequiredPerCallChecker::configure(StrictRequiredPerCallMode::Warn);

        $captured = $this->captureFirstWarning(static function (): void {
            StrictRequiredPerCallChecker::maybeWarn(
                'malformed-json',
                'GET',
                '/foo',
                '200',
                'application/json',
                ['/' => ['anything']],
            );
        });

        $this->assertNull($captured);
        $this->assertStringContainsString(
            "[OpenAPI Strict Required per-call] NOTE: spec 'malformed-json' failed to load",
            $this->readStderr(),
        );
    }

    #[Test]
    public function warn_mode_emits_pointers_in_alphabetical_order(): void
    {
        // CI parsers grep the message and split on the per-pointer lines;
        // a deterministic order across runs matters. The checker calls
        // `ksort($pointers)` so the output is alphabetic regardless of
        // input iteration order. Pin this so a refactor that drops the
        // ksort does not silently make CI diffs noisy.
        StrictRequiredPerCallChecker::configure(StrictRequiredPerCallMode::Warn);

        // /teams/{id} declares `id`+`data` required at root, and `name`
        // required under /data. Body adds both unwanted optional fields
        // so two pointers drift simultaneously — the order in the output
        // must always be `/` before `/data`.
        $captured = $this->captureFirstWarning(static function (): void {
            StrictRequiredPerCallChecker::maybeWarn(
                self::SPEC_NAME,
                'GET',
                '/teams/{id}',
                '200',
                'application/json',
                [
                    // Insertion order deliberately reversed to verify ksort.
                    '/data' => ['created_at', 'name'],
                    '/' => ['data', 'extra_root_field', 'id'],
                ],
            );
        });

        $this->assertNotNull($captured);
        $rootPos = strpos($captured, '/ : ');
        $dataPos = strpos($captured, '/data : ');
        $this->assertNotFalse($rootPos);
        $this->assertNotFalse($dataPos);
        $this->assertLessThan($dataPos, $rootPos, '`/` pointer must precede `/data` in the diagnostic block');
    }

    #[Test]
    public function warn_mode_does_not_warn_when_spec_load_fails_but_emits_note(): void
    {
        StrictRequiredPerCallChecker::configure(StrictRequiredPerCallMode::Warn);

        // The loader has no spec named 'no-such-spec'; per-call must not
        // escalate SpecFileNotFoundException into a per-test warning, but
        // emits a one-shot NOTE per spec so the cause is visible.
        $captured = $this->captureFirstWarning(static function (): void {
            StrictRequiredPerCallChecker::maybeWarn(
                'no-such-spec',
                'GET',
                '/foo',
                '200',
                'application/json',
                ['/' => ['anything']],
            );
        });

        $this->assertNull($captured);

        $stderr = $this->readStderr();
        $this->assertStringContainsString('[OpenAPI Strict Required per-call] NOTE:', $stderr);
        $this->assertStringContainsString("spec 'no-such-spec' failed to load", $stderr);
    }

    #[Test]
    public function note_is_only_emitted_once_per_dedupe_key(): void
    {
        StrictRequiredPerCallChecker::configure(StrictRequiredPerCallMode::Warn);

        // Spec-load failure dedupes per spec name. Three calls with the
        // same spec must emit one NOTE; two distinct specs emit two.
        for ($i = 0; $i < 3; $i++) {
            StrictRequiredPerCallChecker::maybeWarn(
                'no-such-spec',
                'GET',
                '/foo',
                '200',
                'application/json',
                ['/' => ['anything']],
            );
        }
        StrictRequiredPerCallChecker::maybeWarn(
            'also-no-such-spec',
            'GET',
            '/foo',
            '200',
            'application/json',
            ['/' => ['anything']],
        );

        $stderr = $this->readStderr();
        $occurrences = substr_count($stderr, '[OpenAPI Strict Required per-call] NOTE:');
        $this->assertSame(2, $occurrences, 'expected one NOTE per distinct spec, got: ' . $stderr);
    }

    #[Test]
    public function reset_clears_emitted_notes_so_dedupe_starts_fresh(): void
    {
        StrictRequiredPerCallChecker::configure(StrictRequiredPerCallMode::Warn);

        // First call writes a NOTE; second is suppressed by dedupe.
        StrictRequiredPerCallChecker::maybeWarn(
            'no-such-spec',
            'GET',
            '/foo',
            '200',
            'application/json',
            ['/' => ['anything']],
        );

        // Reset clears the dedupe set; the same call should write again.
        StrictRequiredPerCallChecker::reset();
        StrictRequiredPerCallChecker::configure(StrictRequiredPerCallMode::Warn);

        StrictRequiredPerCallChecker::maybeWarn(
            'no-such-spec',
            'GET',
            '/foo',
            '200',
            'application/json',
            ['/' => ['anything']],
        );

        $stderr = $this->readStderr();
        $occurrences = substr_count($stderr, '[OpenAPI Strict Required per-call] NOTE:');
        $this->assertSame(2, $occurrences);
    }

    #[Test]
    public function warn_mode_skips_when_pointers_map_is_empty(): void
    {
        StrictRequiredPerCallChecker::configure(StrictRequiredPerCallMode::Warn);

        $captured = $this->captureFirstWarning(static function (): void {
            StrictRequiredPerCallChecker::maybeWarn(
                self::SPEC_NAME,
                'PUT',
                '/signed-url',
                '200',
                'application/json',
                [],
            );
        });

        $this->assertNull($captured);
    }

    /**
     * Capture the first `E_USER_WARNING` triggered by `$callable` and
     * return its message. Returns `null` if no warning was triggered.
     *
     * Wrapping `set_error_handler` lets the test assert against the
     * warning text without `failOnWarning` interfering with the
     * surrounding PHPUnit run.
     */
    private function captureFirstWarning(callable $callable): ?string
    {
        $captured = null;
        set_error_handler(static function (int $errno, string $errstr) use (&$captured): bool {
            if ($captured === null && $errno === E_USER_WARNING) {
                $captured = $errstr;
            }

            return true;
        }, E_USER_WARNING);

        try {
            $callable();
        } finally {
            restore_error_handler();
        }

        return $captured;
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
