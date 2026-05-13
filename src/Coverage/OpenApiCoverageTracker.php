<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Coverage;

use const E_USER_WARNING;

use InvalidArgumentException;
use Studio\OpenApiContractTesting\Spec\OpenApiSpecLoader;
use Studio\OpenApiContractTesting\Validation\Strict\StrictRequiredTracker;

use function array_key_exists;
use function array_keys;
use function count;
use function get_debug_type;
use function in_array;
use function is_array;
use function is_bool;
use function is_int;
use function is_string;
use function preg_match;
use function sprintf;
use function strcasecmp;
use function strcmp;
use function strpos;
use function strtoupper;
use function substr;
use function trigger_error;
use function usort;

/**
 * @phpstan-type RecordedResponseCoverage array{
 *     state: ResponseCoverageState,
 *     hits: int,
 *     skipReason?: ?string,
 * }
 * @phpstan-type EndpointCoverage array{
 *     requestReached: bool,
 *     requestSkipReason: ?string,
 *     responses: array<string, RecordedResponseCoverage>,
 * }
 * @phpstan-type ResponseRow array{
 *     statusKey: string,
 *     contentTypeKey: string,
 *     state: ResponseCoverageState,
 *     hits: int,
 *     skipReason: ?string,
 * }
 * @phpstan-type EndpointSummary array{
 *     endpoint: string,
 *     method: string,
 *     path: string,
 *     operationId: ?string,
 *     state: EndpointCoverageState,
 *     requestReached: bool,
 *     responses: list<ResponseRow>,
 *     coveredResponseCount: int,
 *     skippedResponseCount: int,
 *     totalResponseCount: int,
 *     unexpectedObservations: list<array{statusKey: string, contentTypeKey: string}>,
 * }
 * @phpstan-type CoverageResult array{
 *     endpoints: list<EndpointSummary>,
 *     endpointTotal: int,
 *     endpointFullyCovered: int,
 *     endpointPartial: int,
 *     endpointUncovered: int,
 *     endpointRequestOnly: int,
 *     responseTotal: int,
 *     responseCovered: int,
 *     responseSkipped: int,
 *     responseUncovered: int,
 * }
 * @phpstan-type CoverageStatePayload array{
 *     version: int,
 *     specs: array<string, array<string, array{
 *         requestReached: bool,
 *         requestSkipReason: ?string,
 *         responses: array<string, array{state: string, hits: int, skipReason: ?string}>,
 *     }>>,
 * }
 * @phpstan-type CoverageReportEntry array{
 *     label: string,
 *     renderer: callable(array<string, CoverageResult>): string,
 *     outputFile: ?string,
 * }
 */
final class OpenApiCoverageTracker
{
    /**
     * Wildcard sentinel for "any / no content-type" — used when a recording
     * predates content-type lookup (skipped responses) and when a spec
     * response has no `content` block.
     */
    public const ANY_CONTENT_TYPE = '*';

    /**
     * Format version stamped on every {@see self::exportState()} payload.
     * Bumped on incompatible structural changes; importers must reject
     * unknown versions to avoid misinterpreting future shapes.
     */
    public const STATE_FORMAT_VERSION = 1;

    /**
     * Service-locator slot for the static facade (Issue #229). The Laravel
     * `ValidatesOpenApiSchema` trait cannot receive a tracker via DI (traits
     * have no constructor), so the static methods route through whichever
     * instance the host installed via {@see self::setCurrent()}. PHPUnit's
     * extension wires this up at bootstrap; outside of a PHPUnit-managed run
     * the locator lazily mints a default instance on first access.
     */
    private static ?self $current = null;

    /**
     * Per-(spec, endpoint) coverage state. Endpoint key is `"{METHOD} {path}"`.
     *
     * Each (statusKey, contentTypeKey) pair is stored under
     * `"{statusKey}:{contentTypeKey}"` and tracks monotonic state — validated
     * > skipped, never demoted — so observation order across a suite does not
     * matter. `hits` increments on each recording. `skipReason` carries the
     * pattern that triggered the skip (latest recording wins).
     *
     * statusKey is the validator's matchedStatusCode (the literal HTTP status
     * for skipped, or the spec response key for validated/failed). Matching
     * against spec range keys (`5XX`, `default`) happens at compute time via
     * {@see self::statusKeyMatches()}.
     *
     * @var array<string, array<string, EndpointCoverage>>
     */
    private array $covered = [];

    public function __construct() {}

    /**
     * The "current" tracker instance — what the static facade methods
     * delegate to. The PHPUnit extension installs a fresh instance at
     * bootstrap so each test run starts clean; the lazy default exists for
     * unit tests and other host-less call sites that hit the static facade
     * before any setup ran.
     *
     * @internal The locator is a refactor seam for the static→instance
     *           migration in Issue #229. Production code should prefer the
     *           explicit instance API ({@see self::recordRequestOn()},
     *           {@see self::recordResponseOn()}, etc.); the facade exists
     *           only for call sites (the Laravel trait) that cannot receive
     *           DI.
     */
    public static function current(): self
    {
        return self::$current ??= new self();
    }

    /**
     * Install the "current" tracker instance. Called from the PHPUnit
     * extension's bootstrap so the suite-wide tracker is the one wired into
     * the subscriber. Drop the slot with {@see self::resetCurrent()} when
     * you want the next {@see self::current()} call to mint a fresh default.
     *
     * Triggers `E_USER_WARNING` when overwriting an installed instance that
     * already has recorded state — that pattern almost always means a test
     * forgot to call {@see self::resetCurrent()} before re-installing and
     * is about to silently drop observations. Matches the project's
     * existing "loud on suspicious state" style (see also
     * {@see self::warnMalformed()} and the v1-row rejection in
     * {@see StrictRequiredTracker::validateRow()}).
     *
     * @internal
     */
    public static function setCurrent(self $instance): void
    {
        if (self::$current !== null && self::$current->getCoveredOn() !== []) {
            trigger_error(
                '[OpenAPI Coverage] setCurrent() called while the previous '
                . 'instance still holds recorded coverage; observations on '
                . 'the outgoing instance will not contribute to reports. '
                . 'Call resetCurrent() first if this is intentional.',
                E_USER_WARNING,
            );
        }
        self::$current = $instance;
    }

    /**
     * Drop the installed instance so the next {@see self::current()} call
     * mints a fresh default. Symmetric with {@see self::setCurrent()};
     * splitting the two intents (install vs clear) keeps the type
     * signatures honest — `setCurrent(self)` is required-non-null, so
     * accidental `null` arguments are caught at compile time.
     *
     * @internal
     */
    public static function resetCurrent(): void
    {
        self::$current = null;
    }

    /**
     * Mark an endpoint as request-side reached. Used by request validators
     * which see a path/method but no response. Idempotent.
     *
     * `$skipReason`, when non-null, records that the request validator
     * downgraded a failure to Skipped because the response was a documented
     * 4xx (issue #179). Reconciliation mirrors response-side promotion:
     * a later "clean" recording (no reason) clears any prior reason — once
     * the same endpoint has been validated cleanly, subsequent skips do not
     * demote it.
     */
    public static function recordRequest(
        string $specName,
        string $method,
        string $path,
        ?string $skipReason = null,
    ): void {
        self::current()->recordRequestOn($specName, $method, $path, $skipReason);
    }

    /**
     * Record a response observation. `statusKey` is the spec key when the
     * validator matched a declared response (e.g. `"200"`), or the literal
     * HTTP status string when the response was skipped before lookup
     * (e.g. `"503"` matched the `5\d\d` skip pattern). `contentTypeKey` is
     * the spec media-type key (with the spec author's original casing) when
     * a content lookup happened, or null for skipped / 204 / non-JSON-only
     * responses — stored under {@see self::ANY_CONTENT_TYPE}.
     *
     * `validated` states win over `skipped` for the same (status, content)
     * pair across multiple recordings; `hits` accumulates.
     */
    public static function recordResponse(
        string $specName,
        string $method,
        string $path,
        string $statusKey,
        ?string $contentTypeKey,
        bool $schemaValidated,
        ?string $skipReason = null,
    ): void {
        self::current()->recordResponseOn(
            $specName,
            $method,
            $path,
            $statusKey,
            $contentTypeKey,
            $schemaValidated,
            $skipReason,
        );
    }

    /**
     * Reset all recorded coverage to the empty state. Intended for test
     * isolation between runs.
     *
     * @internal
     */
    public static function reset(): void
    {
        self::current()->resetOn();
    }

    /**
     * Snapshot the current tracker state as a JSON-safe array. Used by the
     * paratest worker sidecar writer; the merge CLI reconstructs state via
     * {@see self::importState()}.
     *
     * Enums are serialized as their string `value`. The shape is otherwise
     * a 1:1 mirror of the internal {@see self::$covered} representation, so
     * round-tripping through JSON is lossless when no other writes occur.
     *
     * @internal Stable wire format for sidecar writers / merge CLI. The
     * `version` field gates compatibility — see {@see self::STATE_FORMAT_VERSION}.
     * Public users should not depend on the array shape.
     *
     * @return CoverageStatePayload
     */
    public static function exportState(): array
    {
        return self::current()->exportStateOn();
    }

    /**
     * Union-merge an exported state into the live tracker. Used by the
     * merge CLI to combine N paratest worker sidecars into a single report.
     *
     * Merge rules mirror the single-process {@see self::recordResponse()}
     * promotion semantics — the two paths share
     * {@see self::reconcileResponse()} so they cannot drift:
     *
     * - `requestReached` is OR-merged.
     * - For each `(statusKey, contentTypeKey)` pair, hits accumulate.
     * - `validated` wins over `skipped`. Once an existing entry is
     *   validated, an incoming `skipped` does not demote it.
     * - When both sides are `skipped`, the incoming `skipReason` wins
     *   when non-null (latest-record-wins).
     *
     * Validation is two-pass: the entire payload is parsed and rejected
     * up front before any state is mutated, so a malformed entry deep in
     * the payload cannot leave the tracker in a partially-merged state.
     *
     * @internal Companion to {@see self::exportState()}. Used by the merge CLI
     * and not part of the user-facing API.
     *
     * @param array<string, mixed> $state
     *
     * @throws InvalidArgumentException when the payload shape is unrecognised
     */
    public static function importState(array $state): void
    {
        self::current()->importStateOn($state);
    }

    /**
     * Returns true when any record (request or response) exists for the given
     * spec. The PHPUnit extension uses this to gate the "no coverage to report"
     * short-circuit so the report block only renders when at least one test
     * exercised a spec.
     */
    public static function hasAnyCoverage(string $specName): bool
    {
        return self::current()->hasAnyCoverageOn($specName);
    }

    /**
     * Diagnostic accessor: which `"METHOD path"` keys have any recorded
     * activity (request reached or any response observation), grouped by spec.
     * Intended for tests and CLI tooling that only need an "endpoint touched"
     * predicate; richer shape is available via {@see self::computeCoverage()}.
     *
     * @return array<string, array<string, true>>
     */
    public static function getCovered(): array
    {
        return self::current()->getCoveredOn();
    }

    /**
     * @return CoverageResult
     */
    public static function computeCoverage(string $specName): array
    {
        return self::current()->computeCoverageOn($specName);
    }

    /**
     * Instance counterpart of {@see self::recordRequest()} (Issue #229).
     */
    public function recordRequestOn(
        string $specName,
        string $method,
        string $path,
        ?string $skipReason = null,
    ): void {
        $key = self::endpointKey($method, $path);
        // Gate the reconciliation on `requestReached` rather than
        // `isset($covered[...])`. An entry can pre-exist purely because
        // `recordResponseOn()` initialised it (the trait orchestrator
        // currently runs request-before-response, but framework adapters
        // and the bulk-merge `importStateOn()` path can reach the tracker
        // in any order). Using $existed would treat such an entry as
        // "already cleanly request-validated" and silently drop the
        // skipReason on the first request-side recording — the very
        // mode this feature was added to surface.
        $wasRequestReached = $this->covered[$specName][$key]['requestReached'] ?? false;
        $this->covered[$specName][$key] ??= ['requestReached' => false, 'requestSkipReason' => null, 'responses' => []];
        $this->covered[$specName][$key]['requestReached'] = true;

        if (!$wasRequestReached) {
            // First request-side recording for this endpoint — store as-is.
            $this->covered[$specName][$key]['requestSkipReason'] = $skipReason;

            return;
        }

        $existingReason = $this->covered[$specName][$key]['requestSkipReason'];
        if ($skipReason === null) {
            // Clean recording promotes prior skipped state to validated.
            // Mirrors the response-side `Validated > Skipped` rule.
            $this->covered[$specName][$key]['requestSkipReason'] = null;
        } elseif ($existingReason !== null) {
            // skipped + skipped: latest non-null reason wins.
            $this->covered[$specName][$key]['requestSkipReason'] = $skipReason;
        }
        // else: existing is null (validated) and new has a reason — keep
        // null. Once an endpoint has been cleanly request-validated, a
        // later downgrade does not demote it.
    }

    /**
     * Instance counterpart of {@see self::recordResponse()} (Issue #229).
     */
    public function recordResponseOn(
        string $specName,
        string $method,
        string $path,
        string $statusKey,
        ?string $contentTypeKey,
        bool $schemaValidated,
        ?string $skipReason = null,
    ): void {
        $endpointKey = self::endpointKey($method, $path);
        $contentKey = $contentTypeKey ?? self::ANY_CONTENT_TYPE;
        $responseKey = $statusKey . ':' . $contentKey;

        $this->covered[$specName][$endpointKey] ??= ['requestReached' => false, 'requestSkipReason' => null, 'responses' => []];
        $this->covered[$specName][$endpointKey]['responses'][$responseKey] = self::reconcileResponse(
            $this->covered[$specName][$endpointKey]['responses'][$responseKey] ?? null,
            $schemaValidated ? ResponseCoverageState::Validated : ResponseCoverageState::Skipped,
            1,
            $skipReason,
        );
    }

    /**
     * Instance counterpart of {@see self::reset()} (Issue #229). Direct
     * callers can also just drop the instance — there is no global state
     * to clear.
     */
    public function resetOn(): void
    {
        $this->covered = [];
    }

    /**
     * Instance counterpart of {@see self::exportState()} (Issue #229).
     *
     * @return CoverageStatePayload
     */
    public function exportStateOn(): array
    {
        $specs = [];
        foreach ($this->covered as $specName => $endpoints) {
            $specOut = [];
            foreach ($endpoints as $endpointKey => $entry) {
                $responses = [];
                foreach ($entry['responses'] as $responseKey => $row) {
                    $responses[$responseKey] = [
                        'state' => $row['state']->value,
                        'hits' => $row['hits'],
                        'skipReason' => $row['skipReason'] ?? null,
                    ];
                }
                $specOut[$endpointKey] = [
                    'requestReached' => $entry['requestReached'],
                    'requestSkipReason' => $entry['requestSkipReason'],
                    'responses' => $responses,
                ];
            }
            $specs[$specName] = $specOut;
        }

        return ['version' => self::STATE_FORMAT_VERSION, 'specs' => $specs];
    }

    /**
     * Instance counterpart of {@see self::importState()} (Issue #229).
     *
     * @param array<string, mixed> $state
     *
     * @throws InvalidArgumentException when the payload shape is unrecognised
     */
    public function importStateOn(array $state): void
    {
        $normalised = self::validateStatePayload($state);
        foreach ($normalised as $specName => $endpoints) {
            foreach ($endpoints as $endpointKey => $entry) {
                // Same `wasRequestReached` gate as `recordRequestOn()` —
                // an entry that exists only because a prior `importStateOn`
                // or live `recordResponseOn` initialised it has no
                // request-side history; treat the incoming entry as
                // fresh from the request-side perspective so its
                // `requestSkipReason` is not silently dropped.
                $wasRequestReached = $this->covered[$specName][$endpointKey]['requestReached'] ?? false;
                $this->covered[$specName][$endpointKey] ??= ['requestReached' => false, 'requestSkipReason' => null, 'responses' => []];
                if ($entry['requestReached']) {
                    $this->covered[$specName][$endpointKey]['requestReached'] = true;
                }
                // Mirror the single-record recordRequestOn reconciliation so
                // sequential-record and bulk-merge paths cannot drift:
                //   - first request-side observation: store as-is
                //   - existing.validated wins over incoming.skipped
                //   - incoming.validated promotes existing.skipped to validated
                //   - skipped + skipped: latest non-null reason wins
                // Only entries whose incoming side actually carries a
                // request-side observation (`requestReached === true`)
                // participate in reconciliation — a response-only
                // incoming entry leaves the request-side state alone.
                $incomingReason = $entry['requestSkipReason'];
                if ($entry['requestReached']) {
                    if (!$wasRequestReached) {
                        $this->covered[$specName][$endpointKey]['requestSkipReason'] = $incomingReason;
                    } else {
                        $existingReason = $this->covered[$specName][$endpointKey]['requestSkipReason'];
                        if ($incomingReason === null) {
                            $this->covered[$specName][$endpointKey]['requestSkipReason'] = null;
                        } elseif ($existingReason !== null) {
                            $this->covered[$specName][$endpointKey]['requestSkipReason'] = $incomingReason;
                        }
                    }
                }
                foreach ($entry['responses'] as $responseKey => $row) {
                    $this->covered[$specName][$endpointKey]['responses'][$responseKey] = self::reconcileResponse(
                        $this->covered[$specName][$endpointKey]['responses'][$responseKey] ?? null,
                        $row['state'],
                        $row['hits'],
                        $row['skipReason'],
                    );
                }
            }
        }
    }

    /**
     * Instance counterpart of {@see self::hasAnyCoverage()} (Issue #229).
     */
    public function hasAnyCoverageOn(string $specName): bool
    {
        return ($this->covered[$specName] ?? []) !== [];
    }

    /**
     * Instance counterpart of {@see self::getCovered()} (Issue #229).
     *
     * @return array<string, array<string, true>>
     */
    public function getCoveredOn(): array
    {
        $external = [];

        foreach ($this->covered as $spec => $endpoints) {
            foreach ($endpoints as $endpointKey => $entry) {
                if ($entry['requestReached'] === false && $entry['responses'] === []) {
                    continue;
                }
                $external[$spec][$endpointKey] = true;
            }
        }

        return $external;
    }

    /**
     * Instance counterpart of {@see self::computeCoverage()} (Issue #229).
     *
     * @return CoverageResult
     */
    public function computeCoverageOn(string $specName): array
    {
        $spec = OpenApiSpecLoader::load($specName);
        $recordedEndpoints = $this->covered[$specName] ?? [];
        $endpoints = [];

        $endpointFullyCovered = 0;
        $endpointPartial = 0;
        $endpointUncovered = 0;
        $endpointRequestOnly = 0;
        $responseTotal = 0;
        $responseCovered = 0;
        $responseSkipped = 0;

        $declaredEndpoints = self::collectDeclaredEndpoints($specName, $spec);

        foreach ($declaredEndpoints as $declared) {
            $endpointKey = $declared['endpoint'];
            $recorded = $recordedEndpoints[$endpointKey] ?? null;
            $rows = self::buildResponseRows($declared['responses'], $recorded['responses'] ?? []);
            $unexpected = self::collectUnexpectedObservations($declared['responses'], $recorded['responses'] ?? []);

            $coveredCount = 0;
            $skippedCount = 0;
            foreach ($rows as $row) {
                if ($row['state'] === ResponseCoverageState::Validated) {
                    $coveredCount++;
                } elseif ($row['state'] === ResponseCoverageState::Skipped) {
                    $skippedCount++;
                }
            }
            $totalCount = count($rows);

            $requestReached = $recorded['requestReached'] ?? false;
            $hasAnyResponseObservation = ($recorded['responses'] ?? []) !== [];
            $state = self::deriveEndpointState(
                totalDeclared: $totalCount,
                covered: $coveredCount,
                skipped: $skippedCount,
                requestReached: $requestReached,
                hasAnyResponseObservation: $hasAnyResponseObservation,
            );

            match ($state) {
                EndpointCoverageState::AllCovered => $endpointFullyCovered++,
                EndpointCoverageState::Partial => $endpointPartial++,
                EndpointCoverageState::Uncovered => $endpointUncovered++,
                EndpointCoverageState::RequestOnly => $endpointRequestOnly++,
            };

            $responseTotal += $totalCount;
            $responseCovered += $coveredCount;
            $responseSkipped += $skippedCount;

            $endpoints[] = [
                'endpoint' => $endpointKey,
                'method' => $declared['method'],
                'path' => $declared['path'],
                'operationId' => $declared['operationId'],
                'state' => $state,
                'requestReached' => $requestReached,
                'responses' => $rows,
                'coveredResponseCount' => $coveredCount,
                'skippedResponseCount' => $skippedCount,
                'totalResponseCount' => $totalCount,
                'unexpectedObservations' => $unexpected,
            ];
        }

        return [
            'endpoints' => $endpoints,
            'endpointTotal' => count($endpoints),
            'endpointFullyCovered' => $endpointFullyCovered,
            'endpointPartial' => $endpointPartial,
            'endpointUncovered' => $endpointUncovered,
            'endpointRequestOnly' => $endpointRequestOnly,
            'responseTotal' => $responseTotal,
            'responseCovered' => $responseCovered,
            'responseSkipped' => $responseSkipped,
            'responseUncovered' => $responseTotal - $responseCovered - $responseSkipped,
        ];
    }

    /**
     * Apply one (statusKey, contentTypeKey) observation onto an existing
     * recorded entry (or create a new one). The single source of truth for
     * the tracker's promotion semantics — shared between
     * {@see self::recordResponse()} (single-record path) and the bulk-merge
     * path used by {@see self::importState()} so the two cannot drift.
     *
     * Rules:
     * - new entry: store as-is, with `skipReason` cleared on Validated;
     * - hits accumulate;
     * - Validated wins over Skipped and clears `skipReason`;
     * - Skipped + Skipped: latest non-null `skipReason` wins.
     *
     * @param null|RecordedResponseCoverage $existing
     *
     * @return RecordedResponseCoverage
     */
    private static function reconcileResponse(
        ?array $existing,
        ResponseCoverageState $incomingState,
        int $incomingHits,
        ?string $incomingSkipReason,
    ): array {
        if ($existing === null) {
            return [
                'state' => $incomingState,
                'hits' => $incomingHits,
                'skipReason' => $incomingState === ResponseCoverageState::Validated ? null : $incomingSkipReason,
            ];
        }

        $existing['hits'] += $incomingHits;
        if ($incomingState === ResponseCoverageState::Validated) {
            // Promote skipped → validated; once validated, stay validated.
            $existing['state'] = ResponseCoverageState::Validated;
            $existing['skipReason'] = null;
        } elseif (
            $existing['state'] === ResponseCoverageState::Skipped &&
            $incomingState === ResponseCoverageState::Skipped &&
            $incomingSkipReason !== null
        ) {
            // Latest skipReason wins so the renderer surfaces the most recent
            // skip pattern. Typically all-the-same in practice but per-test
            // overrides should not be silently dropped.
            $existing['skipReason'] = $incomingSkipReason;
        }

        return $existing;
    }

    /**
     * Validate the payload structurally and resolve enums up-front. Returns
     * a normalised, fully-typed structure ready for direct application.
     * Throwing at any point leaves {@see self::$covered} untouched.
     *
     * @param array<string, mixed> $state
     *
     * @return array<string, array<string, array{
     *     requestReached: bool,
     *     requestSkipReason: ?string,
     *     responses: array<string, RecordedResponseCoverage>,
     * }>>
     */
    private static function validateStatePayload(array $state): array
    {
        if (!array_key_exists('version', $state)) {
            throw new InvalidArgumentException('coverage state payload is missing "version"');
        }
        if ($state['version'] !== self::STATE_FORMAT_VERSION) {
            throw new InvalidArgumentException(sprintf(
                'unsupported coverage state version: %s (expected %d)',
                is_int($state['version']) || is_string($state['version']) ? (string) $state['version'] : get_debug_type($state['version']),
                self::STATE_FORMAT_VERSION,
            ));
        }
        if (!isset($state['specs']) || !is_array($state['specs'])) {
            throw new InvalidArgumentException('coverage state payload is missing "specs" map');
        }

        $normalised = [];
        foreach ($state['specs'] as $specName => $endpoints) {
            if (!is_string($specName) || !is_array($endpoints)) {
                throw new InvalidArgumentException('invalid spec entry in coverage state payload');
            }
            $normalisedEndpoints = [];
            foreach ($endpoints as $endpointKey => $entry) {
                if (!is_string($endpointKey) || !is_array($entry)) {
                    throw new InvalidArgumentException('invalid endpoint entry in coverage state payload');
                }
                $normalisedEndpoints[$endpointKey] = self::normaliseEndpointEntry($entry);
            }
            $normalised[$specName] = $normalisedEndpoints;
        }

        return $normalised;
    }

    /**
     * @param array<string, mixed> $entry
     *
     * @return array{requestReached: bool, requestSkipReason: ?string, responses: array<string, RecordedResponseCoverage>}
     */
    private static function normaliseEndpointEntry(array $entry): array
    {
        $requestReached = isset($entry['requestReached']) && is_bool($entry['requestReached'])
            ? $entry['requestReached']
            : false;
        // `requestSkipReason` is optional in the wire format: paratest
        // sidecars written by older library versions (pre-#179) omit the
        // field, and a missing key normalises to null. Importer stays
        // backward-compatible with v1 payloads — no STATE_FORMAT_VERSION
        // bump is needed for the additive field. PRESENT-but-non-string
        // values (int, array, bool — i.e. real corruption) are rejected
        // loudly to match the response-side `state` strictness; silently
        // coercing them to null would mask a buggy serializer or
        // hand-edited sidecar as a clean "validated" recording.
        if (array_key_exists('requestSkipReason', $entry)) {
            $rawSkipReason = $entry['requestSkipReason'];
            if ($rawSkipReason !== null && !is_string($rawSkipReason)) {
                throw new InvalidArgumentException(sprintf(
                    'invalid requestSkipReason in coverage state payload: expected string|null, got %s',
                    get_debug_type($rawSkipReason),
                ));
            }
            $requestSkipReason = $rawSkipReason;
        } else {
            $requestSkipReason = null;
        }
        $responses = isset($entry['responses']) && is_array($entry['responses']) ? $entry['responses'] : [];

        $normalisedResponses = [];
        foreach ($responses as $responseKey => $row) {
            if (!is_string($responseKey) || !is_array($row)) {
                throw new InvalidArgumentException('invalid response entry in coverage state payload');
            }
            $stateRaw = $row['state'] ?? null;
            if (!is_string($stateRaw)) {
                throw new InvalidArgumentException('invalid response state in coverage state payload');
            }
            $state = ResponseCoverageState::tryFrom($stateRaw);
            if ($state === null) {
                throw new InvalidArgumentException(sprintf('invalid response state "%s" in coverage state payload', $stateRaw));
            }
            $normalisedResponses[$responseKey] = [
                'state' => $state,
                'hits' => isset($row['hits']) && is_int($row['hits']) ? $row['hits'] : 0,
                'skipReason' => isset($row['skipReason']) && is_string($row['skipReason']) ? $row['skipReason'] : null,
            ];
        }

        return [
            'requestReached' => $requestReached,
            'requestSkipReason' => $requestSkipReason,
            'responses' => $normalisedResponses,
        ];
    }

    private static function endpointKey(string $method, string $path): string
    {
        return strtoupper($method) . ' ' . $path;
    }

    /**
     * Emit a PHPUnit-visible warning when the spec has structurally invalid
     * branches inside otherwise-valid `paths`. We don't throw — partial
     * coverage data is still useful — but staying silent would understate
     * totals without the user noticing.
     */
    private static function warnMalformed(string $specName, string $detail): void
    {
        trigger_error(
            sprintf("[OpenAPI Coverage] spec '%s': %s", $specName, $detail),
            E_USER_WARNING,
        );
    }

    /**
     * Walk the spec's `paths` map and yield one entry per declared
     * (method, path) operation, listing all (statusKey, contentTypeKey)
     * pairs declared for it. A response with no `content` block contributes
     * a single `(statusKey, '*')` pair so 204-style responses appear in
     * coverage instead of being silently omitted.
     *
     * Malformed spec branches (a path mapped to a non-object, an operation
     * mapped to a non-object, or a response entry mapped to a non-object)
     * are skipped AND announced via E_USER_WARNING — silently dropping them
     * would understate `endpointTotal` / `responseTotal` and let the user
     * believe their spec was fully read. The eager `OpenApiSpecLoader::load()`
     * catches structural errors at bootstrap, but it permits the permissive
     * shapes (`responses: 200` as scalar) that this walker rejects.
     *
     * @param array<string, mixed> $spec
     *
     * @return list<array{
     *     endpoint: string,
     *     method: string,
     *     path: string,
     *     operationId: ?string,
     *     responses: list<array{statusKey: string, contentTypeKey: string}>,
     * }>
     */
    private static function collectDeclaredEndpoints(string $specName, array $spec): array
    {
        /** @var array<string, mixed> $paths */
        $paths = is_array($spec['paths'] ?? null) ? $spec['paths'] : [];
        $declared = [];

        foreach ($paths as $path => $methods) {
            if (!is_array($methods)) {
                self::warnMalformed($specName, sprintf('path %s is not an object (got %s); omitted from coverage', (string) $path, get_debug_type($methods)));

                continue;
            }

            foreach ($methods as $method => $operation) {
                $upper = strtoupper((string) $method);
                if (!in_array($upper, ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], true)) {
                    continue;
                }
                if (!is_array($operation)) {
                    self::warnMalformed($specName, sprintf('operation %s %s is not an object (got %s); omitted from coverage', $upper, (string) $path, get_debug_type($operation)));

                    continue;
                }

                $operationId = is_string($operation['operationId'] ?? null) ? $operation['operationId'] : null;
                $responses = is_array($operation['responses'] ?? null) ? $operation['responses'] : [];

                $responsePairs = [];
                foreach ($responses as $statusKey => $responseSpec) {
                    $statusKeyStr = (string) $statusKey;
                    if (!is_array($responseSpec)) {
                        self::warnMalformed($specName, sprintf('response %s %s %s is not an object (got %s); omitted from coverage', $upper, (string) $path, $statusKeyStr, get_debug_type($responseSpec)));

                        continue;
                    }
                    $content = is_array($responseSpec['content'] ?? null) ? $responseSpec['content'] : null;

                    if ($content === null || $content === []) {
                        $responsePairs[] = ['statusKey' => $statusKeyStr, 'contentTypeKey' => self::ANY_CONTENT_TYPE];

                        continue;
                    }

                    foreach (array_keys($content) as $contentTypeKey) {
                        $responsePairs[] = ['statusKey' => $statusKeyStr, 'contentTypeKey' => (string) $contentTypeKey];
                    }
                }

                $declared[] = [
                    'endpoint' => self::endpointKey($upper, $path),
                    'method' => $upper,
                    'path' => $path,
                    'operationId' => $operationId,
                    'responses' => $responsePairs,
                ];
            }
        }

        // Stable, predictable order: by endpoint key (method + path) so reports
        // are diff-friendly across runs.
        usort($declared, static fn(array $a, array $b): int => strcmp($a['endpoint'], $b['endpoint']));

        return $declared;
    }

    /**
     * For each declared (statusKey, contentTypeKey) pair on an endpoint,
     * compute the resolved state by reconciling against any recordings.
     * Validated wins over skipped; skipped wins over uncovered.
     *
     * Reconciliation pairs each recorded status to declared statuses via
     * exact match first, then via range matching (`5XX`/`5xx`/`default`).
     * Recorded `*` content matches any declared content-type; otherwise
     * matching is case-insensitive on the spec key.
     *
     * @param list<array{statusKey: string, contentTypeKey: string}> $declaredResponses
     * @param array<string, RecordedResponseCoverage> $recordedResponses
     *
     * @return list<ResponseRow>
     */
    private static function buildResponseRows(array $declaredResponses, array $recordedResponses): array
    {
        $rows = [];

        foreach ($declaredResponses as $declared) {
            $bestState = ResponseCoverageState::Uncovered;
            $hits = 0;
            $skipReason = null;

            foreach ($recordedResponses as $recordedKey => $entry) {
                [$recordedStatus, $recordedContent] = self::splitResponseKey($recordedKey);

                if (!self::statusKeyMatches($declared['statusKey'], $recordedStatus)) {
                    continue;
                }
                if (!self::contentTypeMatches($declared['contentTypeKey'], $recordedContent)) {
                    continue;
                }

                if ($entry['state'] === ResponseCoverageState::Validated) {
                    if ($bestState !== ResponseCoverageState::Validated) {
                        // Promotion from uncovered/skipped → drop any hits
                        // accumulated from skipped recordings so the
                        // displayed `hits` number reflects validations only.
                        $hits = $entry['hits'];
                    } else {
                        $hits += $entry['hits'];
                    }
                    $bestState = ResponseCoverageState::Validated;
                    $skipReason = null;

                    continue;
                }

                if ($bestState === ResponseCoverageState::Uncovered) {
                    $bestState = ResponseCoverageState::Skipped;
                    $hits = $entry['hits'];
                    $skipReason = $entry['skipReason'] ?? null;

                    continue;
                }
                if ($bestState === ResponseCoverageState::Skipped) {
                    $hits += $entry['hits'];
                    if (($entry['skipReason'] ?? null) !== null) {
                        $skipReason = $entry['skipReason'];
                    }
                }
            }

            $rows[] = [
                'statusKey' => $declared['statusKey'],
                'contentTypeKey' => $declared['contentTypeKey'],
                'state' => $bestState,
                'hits' => $hits,
                'skipReason' => $skipReason,
            ];
        }

        // Sort within an endpoint: statusKey ascending, contentTypeKey ascending,
        // with `*` last so concrete content-types stay grouped.
        usort($rows, static function (array $a, array $b): int {
            $statusCmp = strcmp($a['statusKey'], $b['statusKey']);
            if ($statusCmp !== 0) {
                return $statusCmp;
            }
            if ($a['contentTypeKey'] === self::ANY_CONTENT_TYPE && $b['contentTypeKey'] !== self::ANY_CONTENT_TYPE) {
                return 1;
            }
            if ($b['contentTypeKey'] === self::ANY_CONTENT_TYPE && $a['contentTypeKey'] !== self::ANY_CONTENT_TYPE) {
                return -1;
            }

            return strcasecmp($a['contentTypeKey'], $b['contentTypeKey']);
        });

        return $rows;
    }

    /**
     * Recorded entries that don't reconcile to any spec declaration are
     * surfaced separately so a test exercising an undocumented response
     * (e.g. real `503` against a spec with no `5XX` declaration) is visible
     * without inflating "uncovered" counts.
     *
     * @param list<array{statusKey: string, contentTypeKey: string}> $declaredResponses
     * @param array<string, RecordedResponseCoverage> $recordedResponses
     *
     * @return list<array{statusKey: string, contentTypeKey: string}>
     */
    private static function collectUnexpectedObservations(array $declaredResponses, array $recordedResponses): array
    {
        $unexpected = [];

        foreach ($recordedResponses as $recordedKey => $_entry) {
            [$recordedStatus, $recordedContent] = self::splitResponseKey($recordedKey);

            $matched = false;
            foreach ($declaredResponses as $declared) {
                if (
                    self::statusKeyMatches($declared['statusKey'], $recordedStatus) &&
                    self::contentTypeMatches($declared['contentTypeKey'], $recordedContent)
                ) {
                    $matched = true;

                    break;
                }
            }

            if (!$matched) {
                $unexpected[] = ['statusKey' => $recordedStatus, 'contentTypeKey' => $recordedContent];
            }
        }

        // Stable order for diff-friendly output.
        usort($unexpected, static function (array $a, array $b): int {
            $cmp = strcmp($a['statusKey'], $b['statusKey']);

            return $cmp !== 0 ? $cmp : strcasecmp($a['contentTypeKey'], $b['contentTypeKey']);
        });

        return $unexpected;
    }

    /**
     * Determine the endpoint-level state from declared / covered / skipped
     * counts. `request-only` covers two situations: (a) no responses are
     * declared in spec and the request hook fired ("auto-validate-request
     * without auto-assert"), and (b) responses ARE declared but every
     * observation reconciled only to `unexpectedObservations` so the
     * declared coverage is empty — without this carve-out the endpoint
     * would read `uncovered` despite test traffic.
     */
    private static function deriveEndpointState(
        int $totalDeclared,
        int $covered,
        int $skipped,
        bool $requestReached,
        bool $hasAnyResponseObservation,
    ): EndpointCoverageState {
        if ($totalDeclared === 0) {
            // No response definitions in spec — this happens for operations
            // declared without a `responses` block. Treat as request-only when
            // the endpoint was reached (request hook fired) so it's not lost.
            return $requestReached || $hasAnyResponseObservation
                ? EndpointCoverageState::RequestOnly
                : EndpointCoverageState::Uncovered;
        }

        if ($covered === 0 && $skipped === 0) {
            return $requestReached || $hasAnyResponseObservation
                ? EndpointCoverageState::RequestOnly
                : EndpointCoverageState::Uncovered;
        }

        if ($covered === $totalDeclared) {
            return EndpointCoverageState::AllCovered;
        }

        return EndpointCoverageState::Partial;
    }

    /**
     * Spec key matches recorded key when:
     * - exact case-insensitive match
     * - `default` matches anything (asymmetric — only when `$specKey === 'default'`,
     *   never when the recording is `'default'`)
     * - `1XX`/`2XX`/`3XX`/`4XX`/`5XX` (case-insensitive) matches a literal
     *   3-digit status whose first digit equals the leading digit. Range
     *   matching is **symmetric**: spec=`5XX` vs recorded=`503` and the
     *   reverse both succeed. The validator currently records literal statuses
     *   so the reverse-direction branch supports tests and framework-agnostic
     *   external callers that may pass range keys directly to `recordResponse()`.
     */
    private static function statusKeyMatches(string $specKey, string $recordedKey): bool
    {
        if (strcasecmp($specKey, $recordedKey) === 0) {
            return true;
        }

        if (strcasecmp($specKey, 'default') === 0) {
            return true;
        }

        // Range key on the spec side: e.g. spec="5XX" recorded="503".
        if (preg_match('/^([1-5])[xX]{2}$/', $specKey, $matches) === 1 &&
            preg_match('/^[1-5][0-9]{2}$/', $recordedKey) === 1 &&
            $recordedKey[0] === $matches[1]
        ) {
            return true;
        }

        // Range key on the recorded side is unusual (validators record literal
        // statuses) but support symmetry for tests/external callers.
        if (preg_match('/^([1-5])[xX]{2}$/', $recordedKey, $matches) === 1 &&
            preg_match('/^[1-5][0-9]{2}$/', $specKey) === 1 &&
            $specKey[0] === $matches[1]
        ) {
            return true;
        }

        return false;
    }

    /**
     * `*` (the wildcard sentinel) on either side matches anything; otherwise
     * comparison is case-insensitive against the spec author's literal key.
     */
    private static function contentTypeMatches(string $specContentType, string $recordedContentType): bool
    {
        if ($specContentType === self::ANY_CONTENT_TYPE || $recordedContentType === self::ANY_CONTENT_TYPE) {
            return true;
        }

        return strcasecmp($specContentType, $recordedContentType) === 0;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private static function splitResponseKey(string $key): array
    {
        $colonPos = strpos($key, ':');
        if ($colonPos === false) {
            return [$key, self::ANY_CONTENT_TYPE];
        }

        return [substr($key, 0, $colonPos), substr($key, $colonPos + 1)];
    }
}
