<?php

declare(strict_types=1);

namespace Studio\Gesso\Internal;

use const E_USER_DEPRECATED;

use InvalidArgumentException;

use function array_key_exists;
use function array_keys;
use function array_map;
use function array_sum;
use function array_unique;
use function array_values;
use function count;
use function implode;
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
 */
final class Deprecations
{
    public const PREFIX = '[Gesso deprecation]';

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
     * The one-line residual report written after a test run, or `null` when no
     * deprecated surface was used. Silence is the "ready for the next major"
     * signal, so an empty state must produce no line at all.
     */
    public static function summaryLine(): ?string
    {
        $counts = self::counts();
        if ($counts === []) {
            return null;
        }

        $versions = array_values(array_unique(array_map(
            static fn(string $id): string => self::$removedIn[$id],
            array_keys($counts),
        )));

        // One shared removal version is the normal case and reads better as a
        // trailing sentence; a mixed set has to carry the version per id or the
        // sentence would claim something false about some of them.
        $single = count($versions) === 1;
        $entries = [];
        foreach ($counts as $id => $count) {
            $entries[] = $single
                ? sprintf('%s (%d)', $id, $count)
                : sprintf('%s (%d, removed in %s)', $id, $count, self::$removedIn[$id]);
        }

        return sprintf(
            "%s %d deprecated surface(s) still in use, %d call(s): %s.%s\n",
            self::PREFIX,
            count($counts),
            array_sum($counts),
            implode(', ', $entries),
            $single ? sprintf(' All are removed in Gesso %s.', $versions[0]) : '',
        );
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
