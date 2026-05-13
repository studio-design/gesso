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

    /**
     * Dedupe set keyed by `"<kind>:<spec>:<endpoint>:<response>:<extra>"`.
     * Each NOTE channel (spec-load failure, unresolved schema, disjunction-
     * covered observation) writes at most once per key per process so a
     * long test suite cannot flood STDERR with the same diagnostic.
     *
     * Cleared by {@see self::reset()} so paratest workers and repeated
     * extension bootstraps see a fresh budget.
     *
     * @var array<string, true>
     */
    private static array $emittedNotes = [];

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
     * Reset the mode back to {@see StrictRequiredPerCallMode::Off} and
     * clear the emitted-NOTE dedupe set. Mirrors
     * {@see StrictRequiredTracker::reset()} so test isolation only needs
     * one teardown call per checker.
     *
     * @internal
     */
    public static function reset(): void
    {
        self::$mode = StrictRequiredPerCallMode::Off;
        self::$emittedNotes = [];
    }

    /**
     * @internal Used by tests / extension to verify the wired mode.
     */
    public static function isEnabled(): bool
    {
        return self::$mode->isEnabled();
    }

    /**
     * @internal Used by tests / extension to verify the wired mode. Most
     *           callers should prefer {@see self::isEnabled()} which
     *           returns the on/off discriminator without exposing the
     *           full enum.
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
     * Infrastructure-level no-ops (spec load failures, unresolvable
     * schemas, unwalkable / disjunction-covered roots) emit a one-shot
     * stderr NOTE rather than escalating to a per-test warning. Escalating
     * these to `E_USER_WARNING` would convert an infrastructure problem
     * (a spec file unlinked mid-run, a `$ref` resolved to an unexpected
     * shape, an `anyOf` root that has no AND-semantic for `required`)
     * into a `failOnWarning=true` per-test failure attributed to whichever
     * test happened to run next — the wrong fingerprint. The dedupe key
     * keeps NOTE volume bounded across long test suites.
     *
     * @param array<string, list<string>> $pointers map of JSON-Pointer-like
     *                                              strings to lists of object keys observed at that node
     *
     * @internal Routed through {@see OpenApiResponseValidator}'s Success-only
     *           branch; the parameter shape is not part of the SemVer-frozen
     *           public API.
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

        $upperMethod = strtoupper($method);
        $endpointId = sprintf('%s %s', $upperMethod, $path);
        $responseId = sprintf('%s/%s', $statusKey, $contentTypeKey);

        try {
            $spec = OpenApiSpecLoader::load($specName);
        } catch (InvalidOpenApiSpecException|SpecFileNotFoundException $e) {
            self::noteOnce(
                'spec-load:' . $specName,
                sprintf(
                    "[OpenAPI Strict Required per-call] NOTE: spec '%s' failed to load at runtime; "
                    . 'per-call drift detection skipped for subsequent calls against this spec. '
                    . "Cause: %s. (This usually means the spec file was modified between bootstrap and now.)\n",
                    $specName,
                    $e->getMessage(),
                ),
            );

            return;
        }

        $schemaNode = StrictRequiredSchemaWalker::resolveResponseSchema(
            $spec,
            $upperMethod,
            $path,
            $statusKey,
            $contentTypeKey,
        );
        if ($schemaNode === null) {
            self::noteOnce(
                'unresolved:' . $specName . ':' . $endpointId . ':' . $responseId,
                sprintf(
                    '[OpenAPI Strict Required per-call] NOTE: response schema could not be resolved for %s '
                    . "(%s in spec '%s'); per-call drift detection skipped. (Path matcher / asserter "
                    . 'key disagreement, or $ref resolved to an unexpected shape — bug-level; the run-level '
                    . "asserter surfaces the same condition as an unresolved-groups NOTE at run end.)\n",
                    $endpointId,
                    $responseId,
                    $specName,
                ),
            );

            return;
        }

        $analysis = StrictRequiredSchemaWalker::analyse($schemaNode);

        ksort($pointers);

        $missingByPointer = [];
        foreach ($pointers as $pointer => $observedKeys) {
            $lookup = $analysis->lookup($pointer);
            if ($lookup instanceof StrictRequiredDisjunctionMatch) {
                // Same rule as the asserter: `required` has no AND-semantic
                // across `anyOf` / `oneOf`, so "add to required" advice
                // would mislead. Drop the observation, but emit a one-shot
                // NOTE so per-call-only configurations can still see that
                // the endpoint is invisible to per-call drift detection.
                self::noteOnce(
                    sprintf(
                        'disjunction:%s:%s:%s:%s',
                        $specName,
                        $endpointId,
                        $responseId,
                        $lookup->coveringPointer,
                    ),
                    sprintf(
                        "[OpenAPI Strict Required per-call] NOTE: %s (%s in spec '%s') observation at "
                        . "pointer '%s' is covered by %s at '%s'; per-call drift detection skipped because "
                        . '`required` has no AND-semantic across disjunctions. Pin the shape with `allOf` '
                        . "if you need per-call coverage here.\n",
                        $endpointId,
                        $responseId,
                        $specName,
                        $pointer,
                        $lookup->reason,
                        $lookup->coveringPointer === '' ? '<root>' : $lookup->coveringPointer,
                    ),
                );

                continue;
            }

            $missing = array_values(array_diff($observedKeys, $lookup->required));
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

    /**
     * Emit `$message` to stderr (via the extension's overridable writer so
     * tests can capture, and so paratest worker NOTEs route through the
     * same path as every other extension diagnostic) at most once per
     * `$key` per process. Keys are cleared by {@see self::reset()}.
     *
     * The message must include its own trailing newline — `writeStderr`
     * does no formatting.
     */
    private static function noteOnce(string $key, string $message): void
    {
        if (isset(self::$emittedNotes[$key])) {
            return;
        }
        self::$emittedNotes[$key] = true;
        OpenApiCoverageExtension::writeStderr($message);
    }
}
