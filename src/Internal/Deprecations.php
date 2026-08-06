<?php

declare(strict_types=1);

namespace Studio\Gesso\Internal;

use const E_USER_DEPRECATED;

use InvalidArgumentException;

use function array_key_exists;
use function array_map;
use function array_unique;
use function array_values;
use function count;
use function get_debug_type;
use function implode;
use function is_array;
use function is_int;
use function is_string;
use function ksort;
use function sprintf;
use function trigger_error;
use function trim;

/**
 * The single emitter for Gesso's `E_USER_DEPRECATED` channel (issue #499).
 *
 * A deprecation that does not name its successor and its removal version is
 * unrepresentable here: `$replacement` and `$removedIn` are required and
 * rejected when empty. That is the point of routing every deprecation through
 * one method instead of scattering `trigger_error()` calls — the registry in
 * `tests/fixtures/compatibility/v2-deprecations.json` can then be checked
 * against the call sites, and a major that removes a surface has a list to
 * delete from.
 *
 * Notices dedup per `$id` per process, matching the `E_USER_WARNING` channel's
 * contract (`docs/supported-features.md`). Counts keep accumulating after the
 * first emission so the end-of-run summary can report how much of a suite
 * still depends on the surface.
 *
 * @internal Not part of the package's public API. Do not use from user code.
 *
 * @phpstan-type DeprecationEntry array{count: int<1, max>, removed_in: string}
 * @phpstan-type DeprecationStatePayload array{
 *     version: int,
 *     deprecations: array<string, DeprecationEntry>,
 * }
 */
final class Deprecations
{
    public const PREFIX = '[Gesso deprecation]';

    /**
     * Wire version of {@see self::exportState()}, carried inside the sidecar
     * envelope and owned by this class rather than by the envelope version —
     * the same split the coverage and strict-required trackers use.
     */
    public const STATE_VERSION = 1;

    /** @var array<string, int<1, max>> */
    private static array $counts = [];

    /** @var array<string, string> */
    private static array $removedIn = [];

    private function __construct() {}

    /**
     * Record one use of a deprecated surface, emitting the notice on the first
     * use in this process.
     *
     * @param string $id Stable dedup key, also the registry key. Dotted
     *                   and scoped by surface, e.g.
     *                   `laravel.config.auto_inject_dummy_bearer`.
     * @param string $subject Human-readable name of what is deprecated, as it
     *                        reads in the middle of a sentence.
     * @param string $replacement What to use instead.
     * @param string $removedIn Gesso version that removes the surface, e.g. `3.0`.
     */
    public static function notice(
        string $id,
        string $subject,
        string $replacement,
        string $removedIn,
    ): void {
        self::rejectEmpty('id', $id);
        self::rejectEmpty('subject', $subject);
        self::rejectEmpty('replacement', $replacement);
        self::rejectEmpty('removedIn', $removedIn);

        $alreadyNotified = array_key_exists($id, self::$counts);
        self::$counts[$id] = $alreadyNotified ? self::$counts[$id] + 1 : 1;
        self::$removedIn[$id] = $removedIn;

        if ($alreadyNotified) {
            return;
        }

        trigger_error(
            sprintf(
                '%s %s is deprecated. Use %s instead. It is removed in Gesso %s. See UPGRADING.md#deprecations.',
                self::PREFIX,
                $subject,
                $replacement,
                $removedIn,
            ),
            E_USER_DEPRECATED,
        );
    }

    /**
     * Per-id call counts for this process, keyed by the `notice()` id.
     *
     * @return array<string, int<1, max>>
     */
    public static function counts(): array
    {
        $counts = self::$counts;
        ksort($counts);

        return $counts;
    }

    /**
     * This process's notice state, in the shape the sidecar envelope carries.
     *
     * A paratest worker never reaches the end-of-run report — it hands its
     * state to `gesso coverage:merge` instead, exactly as the coverage and
     * strict-required trackers do. Exported unconditionally, including as an
     * empty map: the envelope version determines whether the key is present,
     * and "this worker saw none" is the load-bearing half of the report.
     *
     * @return DeprecationStatePayload
     */
    public static function exportState(): array
    {
        $entries = [];
        foreach (self::counts() as $id => $count) {
            $entries[$id] = ['count' => $count, 'removed_in' => self::$removedIn[$id]];
        }

        return ['version' => self::STATE_VERSION, 'deprecations' => $entries];
    }

    /**
     * Sum several workers' exported states into one id → entry map.
     *
     * Counts add; the removal version is taken from the first sidecar that
     * declares the id, since a mixed-version fleet that disagrees about when a
     * surface disappears is reporting a library-version skew, not two facts.
     *
     * @param list<array<string, mixed>> $payloads
     *
     * @return array<string, DeprecationEntry>
     *
     * @throws InvalidArgumentException on an unknown state version or a
     *                                  malformed entry
     */
    public static function mergeStates(array $payloads): array
    {
        $merged = [];
        foreach ($payloads as $payload) {
            foreach (self::parseState($payload) as $id => $entry) {
                $merged[$id] = array_key_exists($id, $merged)
                    ? ['count' => $merged[$id]['count'] + $entry['count'], 'removed_in' => $merged[$id]['removed_in']]
                    : $entry;
            }
        }

        ksort($merged);

        return $merged;
    }

    /**
     * The one-line residual report written after a test run, or `null` when no
     * deprecated surface was used. Silence is the "ready for the next major"
     * signal, so an empty state must produce no line at all.
     *
     * Takes the state as an argument so the sequential PHPUnit run and the
     * merge CLI — which reports a union it never emitted itself — render the
     * same line from the same code.
     *
     * @param array<string, DeprecationEntry> $state
     */
    public static function renderSummary(array $state): ?string
    {
        if ($state === []) {
            return null;
        }

        ksort($state);
        $versions = array_values(array_unique(array_map(
            static fn(array $entry): string => $entry['removed_in'],
            $state,
        )));

        // One shared removal version is the normal case and reads better as a
        // trailing sentence; a mixed set has to carry the version per id or the
        // sentence would claim something false about some of them.
        $single = count($versions) === 1;
        $calls = 0;
        $entries = [];
        foreach ($state as $id => $entry) {
            $calls += $entry['count'];
            $entries[] = $single
                ? sprintf('%s (%d)', $id, $entry['count'])
                : sprintf('%s (%d, removed in %s)', $id, $entry['count'], $entry['removed_in']);
        }

        return sprintf(
            "%s %d deprecated surface(s) still in use, %d call(s): %s.%s\n",
            self::PREFIX,
            count($state),
            $calls,
            implode(', ', $entries),
            $single ? sprintf(' All are removed in Gesso %s.', $versions[0]) : '',
        );
    }

    /**
     * The one-line residual report for this process, or `null` when it used no
     * deprecated surface.
     */
    public static function summaryLine(): ?string
    {
        return self::renderSummary(self::exportState()['deprecations']);
    }

    /**
     * Clear the per-process notice state. Test seam — production code never
     * needs this. Named for the `*::resetWarningStateForTesting()` convention
     * used by the warning channel's emitters.
     *
     * @internal
     */
    public static function resetForTesting(): void
    {
        self::$counts = [];
        self::$removedIn = [];
    }

    /**
     * Validate one exported state payload and return its entry map.
     *
     * Unknown versions are rejected rather than guessed, matching every other
     * sidecar half: a newer worker's state reaching an older merge CLI must
     * fail loudly instead of silently reporting a partial deprecation count,
     * which reads as "ready for the next major".
     *
     * @param array<string, mixed> $payload
     *
     * @return array<string, DeprecationEntry>
     */
    private static function parseState(array $payload): array
    {
        $version = $payload['version'] ?? null;
        if ($version !== self::STATE_VERSION) {
            throw new InvalidArgumentException(sprintf(
                'Unsupported deprecation state version: got %s, expected %d.',
                is_int($version) ? (string) $version : get_debug_type($version),
                self::STATE_VERSION,
            ));
        }

        $entries = $payload['deprecations'] ?? null;
        if (!is_array($entries)) {
            throw new InvalidArgumentException(sprintf(
                'Deprecation state "deprecations" must be an array; got %s.',
                get_debug_type($entries),
            ));
        }

        $parsed = [];
        foreach ($entries as $id => $entry) {
            if (!is_string($id) || trim($id) === '') {
                throw new InvalidArgumentException('Deprecation state keys must be non-empty id strings.');
            }

            if (!is_array($entry) || !is_int($entry['count'] ?? null) || !is_string($entry['removed_in'] ?? null)) {
                throw new InvalidArgumentException(sprintf(
                    'Deprecation state entry "%s" must declare an integer "count" and a string "removed_in".',
                    $id,
                ));
            }

            if ($entry['count'] < 1 || trim($entry['removed_in']) === '') {
                throw new InvalidArgumentException(sprintf(
                    'Deprecation state entry "%s" must declare a positive "count" and a non-empty "removed_in".',
                    $id,
                ));
            }

            $parsed[$id] = ['count' => $entry['count'], 'removed_in' => $entry['removed_in']];
        }

        return $parsed;
    }

    private static function rejectEmpty(string $parameter, string $value): void
    {
        if (trim($value) !== '') {
            return;
        }

        throw new InvalidArgumentException(sprintf(
            'Deprecations::notice() requires a non-empty $%s.',
            $parameter,
        ));
    }
}
