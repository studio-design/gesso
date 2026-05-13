<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Validation\Strict;

use const E_USER_WARNING;

use Studio\OpenApiContractTesting\Exception\InvalidOpenApiSpecException;
use Studio\OpenApiContractTesting\Exception\SpecFileNotFoundException;
use Studio\OpenApiContractTesting\OpenApiResponseValidator;
use Studio\OpenApiContractTesting\PHPUnit\OpenApiCoverageExtension;
use Studio\OpenApiContractTesting\Spec\OpenApiSpecLoader;

use function array_diff;
use function array_values;
use function count;
use function implode;
use function ksort;
use function sort;
use function sprintf;
use function strtoupper;
use function trigger_error;

/**
 * Per-call (single-observation) companion to {@see StrictRequiredAsserter}
 * (Issue #228). Where the asserter aggregates observations across the run
 * and asserts at `ExecutionFinished`, this checker fires immediately on
 * every conformance-passing response so under-described endpoints with only
 * one observation surface as `E_USER_WARNING` — and convert to per-test
 * failures under PHPUnit's `failOnWarning=true`.
 *
 * Trade-off: per-call warns indiscriminately on every legitimately-optional
 * field that happens to be present in any one response. Use it for early
 * visibility on single-test endpoints and pair it with the run-level
 * intersection mode for the safe aggregate gate. See `docs/strict-required.md`
 * "Per-call mode" for the full trade-off discussion.
 *
 * Schema-walking is delegated to {@see StrictRequiredSchemaWalker} — same
 * semantics as the asserter (`allOf` unioned, `anyOf` / `oneOf` skipped at
 * the disjunction node, observations under unresolved schemas silently
 * dropped).
 *
 * Static singleton mirrors {@see StrictRequiredTracker} so the validator
 * can route through a stable static call without changing its public
 * constructor signature.
 *
 * @internal Configured by {@see OpenApiCoverageExtension} and invoked by
 *           {@see OpenApiResponseValidator}; not part of the SemVer-frozen
 *           public API.
 */
final class StrictRequiredPerCallChecker
{
    private static StrictRequiredPerCallMode $mode = StrictRequiredPerCallMode::Off;

    /** Static-only utility — no instances. */
    private function __construct() {}

    /**
     * Set the active per-call mode. Called from the extension's bootstrap
     * after {@see StrictRequiredPerCallMode::fromConfigValue()} has parsed
     * the `strict_required_per_call` parameter.
     *
     * @internal
     */
    public static function configure(StrictRequiredPerCallMode $mode): void
    {
        self::$mode = $mode;
    }

    /**
     * Reset the mode back to {@see StrictRequiredPerCallMode::Off}. Mirrors
     * {@see StrictRequiredTracker::reset()} so test isolation only needs
     * one teardown call per checker.
     *
     * @internal
     */
    public static function reset(): void
    {
        self::$mode = StrictRequiredPerCallMode::Off;
    }

    /**
     * @internal Used by tests / extension to verify the wired mode.
     */
    public static function mode(): StrictRequiredPerCallMode
    {
        return self::$mode;
    }

    /**
     * Compare a single observation's pointer→keys map against the matching
     * spec's `required` arrays and emit one `E_USER_WARNING` if any drift
     * is found. Off mode short-circuits with no spec load.
     *
     * The `$pointers` argument carries the same shape produced by
     * {@see StrictRequiredBodyWalker::collectPointers()} — the validator
     * computes it once and hands it to both the tracker and this checker
     * to avoid double-walking the body.
     *
     * Spec load failures and unresolvable schemas are silently dropped:
     * per-call warnings convert to per-test failures under
     * `failOnWarning=true`, so escalating an infrastructure problem (a spec
     * file unlinked mid-run) into a user-test failure would attribute the
     * fault to the wrong layer. The run-level asserter still surfaces these
     * as a NOTE at `ExecutionFinished`.
     *
     * @param array<string, list<string>> $pointers map of JSON-Pointer-like
     *                                              strings to lists of object keys observed at that node
     */
    public static function maybeWarn(
        string $specName,
        string $method,
        string $path,
        string $statusKey,
        string $contentTypeKey,
        array $pointers,
    ): void {
        if (self::$mode === StrictRequiredPerCallMode::Off) {
            return;
        }
        if ($pointers === []) {
            return;
        }

        try {
            $spec = OpenApiSpecLoader::load($specName);
        } catch (InvalidOpenApiSpecException|SpecFileNotFoundException) {
            return;
        }

        $upperMethod = strtoupper($method);
        $schemaNode = StrictRequiredSchemaWalker::resolveResponseSchema(
            $spec,
            $upperMethod,
            $path,
            $statusKey,
            $contentTypeKey,
        );
        if ($schemaNode === null) {
            return;
        }

        $analysis = StrictRequiredSchemaWalker::collectRequiredByPointer($schemaNode);
        $walked = $analysis['walked'];
        $disjunctions = $analysis['disjunctions'];

        ksort($pointers);

        $missingByPointer = [];
        foreach ($pointers as $pointer => $observedKeys) {
            if (StrictRequiredSchemaWalker::findCoveringDisjunction($pointer, $disjunctions) !== null) {
                // Same rule as the asserter: `required` has no AND-semantic
                // across `anyOf` / `oneOf`, so "add to required" advice
                // would mislead. Silently skip — the run-level asserter
                // emits the unwalkable NOTE.
                continue;
            }

            $specRequired = $walked[$pointer] ?? [];
            $missing = array_values(array_diff($observedKeys, $specRequired));
            if ($missing === []) {
                continue;
            }
            sort($missing);
            $missingByPointer[$pointer] = $missing;
        }

        if ($missingByPointer === []) {
            return;
        }

        trigger_error(
            self::renderMessage($upperMethod, $path, $statusKey, $contentTypeKey, $missingByPointer),
            E_USER_WARNING,
        );
    }

    /**
     * Render the diagnostic emitted on a drifting observation. Lives as a
     * separate method so unit tests can pin the exact wire format —
     * downstream CI parsers (Slack notifiers, log scrapers) commonly grep
     * the prefix and split on the colons.
     *
     * @param array<string, list<string>> $missingByPointer
     */
    private static function renderMessage(
        string $method,
        string $path,
        string $statusKey,
        string $contentTypeKey,
        array $missingByPointer,
    ): string {
        $header = sprintf(
            '[OpenAPI Strict Required per-call] WARN: %s %s  %s  %s: response carries %d optional field(s) not declared in `required` at the matching schema pointer(s):',
            $method,
            $path,
            $statusKey,
            $contentTypeKey,
            self::sumMissing($missingByPointer),
        );

        $lines = [];
        foreach ($missingByPointer as $pointer => $missing) {
            $lines[] = sprintf('  %s : %s', $pointer, implode(', ', $missing));
        }

        $footer = "Action: add these fields to the schema's `required` array, or set strict_required_per_call=off if intentional.\n"
            . 'Note: per-call mode warns on every legitimately-optional field present in this single observation. See docs/strict-required.md "Per-call mode" for the trade-off.';

        return $header . "\n" . implode("\n", $lines) . "\n" . $footer;
    }

    /**
     * @param array<string, list<string>> $missingByPointer
     */
    private static function sumMissing(array $missingByPointer): int
    {
        $total = 0;
        foreach ($missingByPointer as $missing) {
            $total += count($missing);
        }

        return $total;
    }
}
