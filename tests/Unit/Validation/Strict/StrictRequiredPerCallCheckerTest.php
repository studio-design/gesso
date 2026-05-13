<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Tests\Unit\Validation\Strict;

use const E_USER_WARNING;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\OpenApiContractTesting\Spec\OpenApiSpecLoader;
use Studio\OpenApiContractTesting\Validation\Strict\StrictRequiredPerCallChecker;
use Studio\OpenApiContractTesting\Validation\Strict\StrictRequiredPerCallMode;

use function restore_error_handler;
use function set_error_handler;

final class StrictRequiredPerCallCheckerTest extends TestCase
{
    private const SPEC_BASE_PATH = __DIR__ . '/../../../fixtures/specs';
    private const SPEC_NAME = 'under-described';

    protected function setUp(): void
    {
        parent::setUp();
        OpenApiSpecLoader::reset();
        OpenApiSpecLoader::configure(self::SPEC_BASE_PATH);
        StrictRequiredPerCallChecker::reset();
    }

    protected function tearDown(): void
    {
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
    public function warn_mode_skips_pointers_under_disjunction(): void
    {
        StrictRequiredPerCallChecker::configure(StrictRequiredPerCallMode::Warn);

        // /either-shape root is `anyOf`. Per-call must not warn about
        // anything under it — the disjunction has no AND-semantic for
        // `required`. The asserter would emit an unwalkable NOTE, but
        // per-call has no NOTE channel; silent skip is the safe default.
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
    }

    #[Test]
    public function warn_mode_silently_skips_unknown_endpoint(): void
    {
        StrictRequiredPerCallChecker::configure(StrictRequiredPerCallMode::Warn);

        // Endpoint not in spec — per-call must not fail-loud here because
        // converting an infrastructure mismatch into a per-test warning
        // would attribute the bug to the wrong test layer.
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
    }

    #[Test]
    public function warn_mode_silently_skips_when_spec_load_fails(): void
    {
        StrictRequiredPerCallChecker::configure(StrictRequiredPerCallMode::Warn);

        // The loader has no spec named 'no-such-spec'; per-call must not
        // escalate the SpecFileNotFoundException into a per-test warning.
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
}
