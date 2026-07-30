<?php

declare(strict_types=1);

namespace Studio\Gesso\Baseline;

use Studio\Gesso\OpenApiValidationResult;

use function array_key_exists;
use function count;

/**
 * Run-level enforcement of a committed violation baseline (issue #402).
 *
 * Installed by the PHPUnit extension when a `baseline_file` is configured
 * and the run is not a generation run. A failing result is suppressed only
 * when **every** one of its issues is baselined — any new violation keeps
 * the full, unmodified assertion failure so the ratchet only ever moves
 * forward. Baselined entries that occur are tracked as hits; the
 * end-of-run summary reports never-hit entries as stale (removable).
 *
 * Like the collector, {@see current()} is nullable on purpose: a `null`
 * enforcer means "no baseline is loaded" and adapters take the normal
 * assertion path, so no lazy default instance may ever exist. The extension
 * installs at most one of collector and enforcer per run.
 *
 * @internal Implementation detail of the violation baseline; the
 *           `baseline_file` / `baseline_stale` extension parameters are
 *           the supported surface.
 */
final class ViolationBaselineEnforcer
{
    private static ?self $current = null;

    /** @var array<string, true> keyed by fingerprint key */
    private array $hitKeys = [];

    public function __construct(private readonly ViolationBaseline $baseline) {}

    public static function current(): ?self
    {
        return self::$current;
    }

    public static function setCurrent(self $enforcer): void
    {
        self::$current = $enforcer;
    }

    public static function resetCurrent(): void
    {
        self::$current = null;
    }

    /**
     * Whether a failing result is fully covered by the baseline. Every
     * baselined issue is marked as hit even when the overall verdict is
     * "not suppressed" — the entry did occur, so it must not be reported
     * as stale just because a new violation failed the same assertion.
     *
     * `$excludeCategory` skips issues of one category from the verdict:
     * after a suppressed body-decode failure the validator ran against an
     * absent placeholder body, so same-side body issues are artifacts of
     * the decode failure, not violations the baseline needs to cover
     * (mirroring the collector's generation-time exclusion).
     *
     * The PSR-7 adapter folds decode failures into the result itself as
     * `parse`-keyword issues, so the same artifact exclusion is derived
     * from the issue list here: only the `parse` issue of an affected
     * category needs baseline coverage, its placeholder siblings do not
     * ({@see ViolationFingerprint::decodeFailureCategories()}).
     */
    public function suppressesResult(
        string $specName,
        OpenApiValidationResult $result,
        string $fallbackMethod,
        string $fallbackPath,
        ?string $excludeCategory = null,
    ): bool {
        $artifactCategories = ViolationFingerprint::decodeFailureCategories($result->issues());
        $allBaselined = true;
        foreach ($result->issues() as $issue) {
            if ($issue->category === $excludeCategory) {
                continue;
            }
            if (
                $issue->keyword !== ViolationFingerprint::KEYWORD_PARSE &&
                isset($artifactCategories[$issue->category])
            ) {
                continue;
            }

            $fingerprint = ViolationFingerprint::fromIssue($specName, $issue, $fallbackMethod, $fallbackPath);
            if ($this->baseline->contains($fingerprint)) {
                $this->hitKeys[$fingerprint->key()] = true;
            } else {
                $allBaselined = false;
            }
        }

        return $allBaselined;
    }

    /**
     * Whether a body-decode failure is baselined. The fingerprint must be
     * rebuilt exactly as the adapters record it at generation time
     * ({@see ViolationFingerprint::forDecodeFailure()}): raw request
     * method / path with no matched status, content-type, or pointer
     * context, because the failure happens before path matching.
     */
    public function suppressesDecodeFailure(
        string $specName,
        string $method,
        string $path,
        string $category,
    ): bool {
        $fingerprint = ViolationFingerprint::forDecodeFailure($specName, $method, $path, $category);

        if (!$this->baseline->contains($fingerprint)) {
            return false;
        }

        $this->hitKeys[$fingerprint->key()] = true;

        return true;
    }

    public function baseline(): ViolationBaseline
    {
        return $this->baseline;
    }

    public function hitCount(): int
    {
        return count($this->hitKeys);
    }

    /**
     * Baseline entries that never occurred during this run, in the
     * baseline's deterministic order. Meaningful only after a full run —
     * a subset run cannot prove an entry no longer occurs.
     *
     * @return list<ViolationFingerprint>
     */
    public function staleEntries(): array
    {
        $stale = [];
        foreach ($this->baseline->sorted() as $fingerprint) {
            if (!array_key_exists($fingerprint->key(), $this->hitKeys)) {
                $stale[] = $fingerprint;
            }
        }

        return $stale;
    }
}
