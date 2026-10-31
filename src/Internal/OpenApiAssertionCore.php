<?php

declare(strict_types=1);

namespace Studio\Gesso\Internal;

use Closure;
use PHPUnit\Framework\AssertionFailedError;
use Studio\Gesso\Baseline\ViolationBaselineCollector;
use Studio\Gesso\Baseline\ViolationBaselineEnforcer;
use Studio\Gesso\Baseline\ViolationFingerprint;
use Studio\Gesso\DecodedBody;
use Studio\Gesso\OpenApiValidationResult;
use Studio\Gesso\PHPUnit\CleanTraceAssert;
use Studio\Gesso\ValidationOutput;
use Studio\Gesso\ValidationOutputFormat;
use Symfony\Component\HttpFoundation\Request;

use function implode;
use function is_scalar;

/**
 * Assertion plumbing shared verbatim by the Laravel, Symfony and PSR-7
 * assertion traits: clean-trace fail/assert, the baseline-aware result
 * assertion, and the HttpFoundation reproduce command the two
 * HttpFoundation adapters hand requests straight through to. Failures go
 * through {@see CleanTraceAssert}.
 *
 * @internal Framework adapter implementation detail.
 */
trait OpenApiAssertionCore
{
    /** @internal Shared body; the adapters' frozen private names forward here. */
    private function httpFoundationReproduceCommand(Request $request): string
    {
        $body = $request->getContent();

        // Cookies live in Request::$cookies, not the header bag, so a Cookie
        // header has to be synthesized or cookie-based auth and cookie
        // parameters would silently vanish from the curl command. The
        // formatter redacts the value.
        $headers = $request->headers->all();
        // ServerBag decodes Basic/Digest auth into php-auth-* entries. They
        // are PHP server-var artifacts, not wire headers, and would print the
        // plaintext password; the Authorization header already carries the
        // credential (redacted by the formatter).
        unset($headers['php-auth-user'], $headers['php-auth-pw'], $headers['php-auth-digest']);
        $cookiePairs = [];
        foreach ($request->cookies->all() as $name => $value) {
            $cookiePairs[] = $name . '=' . (is_scalar($value) ? (string) $value : '');
        }
        if ($cookiePairs !== []) {
            $headers['cookie'] = [implode('; ', $cookiePairs)];
        }

        return CurlCommandFormatter::format(
            $request->getMethod(),
            $request->getUri(),
            $headers,
            $body !== '' ? $body : null,
            $request->headers->get('Content-Type'),
        );
    }

    /**
     * Run a body-decode step; during a baseline generation run (issue #402)
     * a decode failure (the `AssertionFailedError` raised by the extract
     * helper) is recorded as a body-category fingerprint and demoted, and an
     * absent body is returned so the rest of the validation pipeline still
     * runs — mirroring how the PSR-7 adapter folds adapter-level body errors
     * into the validation result while validating everything else. Any
     * further violations are then demoted and recorded by the normal assert
     * path, except same-side body issues: the validator saw an absent
     * placeholder, not the real (undecodable) body, so its body verdicts are
     * artifacts — `$decodeFailureDemoted` tells the caller to exclude that
     * category when recording. The fingerprint deliberately carries no
     * matched status / content-type context: the failure happens before path
     * matching, so enforcement rebuilds the identical fingerprint from the
     * raw request context alone.
     *
     * During an enforcement run a baselined decode failure is suppressed the
     * same way — absent body, validation continues, same-side body verdicts
     * excluded — while an unbaselined one re-throws as the normal failure.
     * Runs with neither collector nor enforcer re-throw untouched.
     *
     * @param Closure(): DecodedBody $extract
     *
     * @param-out bool $decodeFailureDemoted
     */
    private function extractOrRecordBaselineViolation(
        Closure $extract,
        string $specName,
        string $method,
        string $path,
        string $category,
        bool &$decodeFailureDemoted,
    ): DecodedBody {
        $decodeFailureDemoted = false;
        $collector = ViolationBaselineCollector::current();
        $enforcer = ViolationBaselineEnforcer::current();
        if ($collector === null && $enforcer === null) {
            return $extract();
        }

        try {
            return $extract();
        } catch (AssertionFailedError $e) {
            if ($collector !== null) {
                $collector->record(ViolationFingerprint::forDecodeFailure($specName, $method, $path, $category));
                $decodeFailureDemoted = true;

                return DecodedBody::absent();
            }

            // $enforcer is non-null here: the early return above covered the
            // neither-installed case and the collector branch just returned.
            if ($enforcer->suppressesDecodeFailure($specName, $method, $path, $category)) {
                $decodeFailureDemoted = true;

                return DecodedBody::absent();
            }

            throw $e;
        }
    }

    /**
     * Raise the adapter's standard failure for an invalid result. Json mode
     * must end with the parseable document, so it fails without PHPUnit's
     * "Failed asserting that false is true." suffix; text mode keeps the
     * historical assertTrue() message byte-for-byte.
     *
     * During a baseline generation run (issue #402) the failure is demoted
     * instead: fingerprints are recorded and the assertion passes so the
     * whole suite completes in one run. During an enforcement run the
     * failure is suppressed only when every issue is baselined; any new
     * violation falls through to the full, unmodified failure.
     *
     * @param Closure(): string $reproduceCommand built lazily so the curl
     *                                            command is only rendered when the assertion actually fails
     *
     * @internal Shared body; the adapters' frozen private names forward here.
     */
    private function assertOpenApiResult(
        OpenApiValidationResult $result,
        string $specName,
        string $method,
        string $path,
        string $header,
        Closure $reproduceCommand,
        ?string $recordExcludeCategory = null,
    ): void {
        if ($result->isValid()) {
            CleanTraceAssert::assertTrue(true, '');

            return;
        }

        $collector = ViolationBaselineCollector::current();
        if ($collector !== null) {
            $collector->recordResult($specName, $result, $method, $path, $recordExcludeCategory);
            CleanTraceAssert::assertTrue(true, '');

            return;
        }

        $enforcer = ViolationBaselineEnforcer::current();
        if ($enforcer !== null && $enforcer->suppressesResult($specName, $result, $method, $path, $recordExcludeCategory)) {
            CleanTraceAssert::assertTrue(true, '');

            return;
        }

        $message = FailureOutput::compose($header, $result, $reproduceCommand);

        if (ValidationOutput::format() === ValidationOutputFormat::Json) {
            CleanTraceAssert::fail($message);
        }

        CleanTraceAssert::assertTrue(false, $message);
    }
}
