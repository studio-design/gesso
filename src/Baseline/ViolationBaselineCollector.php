<?php

declare(strict_types=1);

namespace Studio\Gesso\Baseline;

use Studio\Gesso\OpenApiValidationResult;
use Studio\Gesso\Validation\Support\SchemaValidatorRunner;

/**
 * Run-level recorder for baseline generation (issue #402).
 *
 * Installed by the PHPUnit extension only when the run is a baseline
 * generation run (`GESSO_BASELINE_GENERATE`). Unlike the tracker seams,
 * {@see current()} is nullable on purpose: a `null` collector means
 * "generate mode is off" and adapters take the normal assertion path, so
 * no lazy default instance may ever exist.
 *
 * @internal Implementation detail of the violation baseline; the
 *           `GESSO_BASELINE_GENERATE` env var and `baseline_file`
 *           extension parameter are the supported surface.
 */
final class ViolationBaselineCollector
{
    private static ?self $current = null;
    private readonly ViolationBaseline $baseline;

    public function __construct()
    {
        $this->baseline = new ViolationBaseline();
    }

    public static function current(): ?self
    {
        return self::$current;
    }

    public static function setCurrent(self $collector): void
    {
        self::$current = $collector;
    }

    public static function resetCurrent(): void
    {
        self::$current = null;
    }

    /**
     * Effective maxErrors for a validator an adapter is about to construct.
     * During a generation run the cap is lifted (0 = unlimited in
     * {@see SchemaValidatorRunner}) —
     * a truncated error list would drop violations from the baseline, and
     * the dropped ones would later resurface as "new" the moment an earlier
     * entry is fixed. An enforcement run ({@see ViolationBaselineEnforcer})
     * lifts it for the mirror-image reason: a new violation truncated away
     * behind baselined ones would let the suppression check pass on an
     * incomplete issue list.
     */
    public static function uncap(int $maxErrors): int
    {
        return self::$current === null && ViolationBaselineEnforcer::current() === null
            ? $maxErrors
            : 0;
    }

    public function record(ViolationFingerprint $fingerprint): void
    {
        $this->baseline->add($fingerprint);
    }

    /**
     * Record every issue of a failing result. The adapter's resolved method
     * and raw request path back-fill issues that carry no context of their
     * own (e.g. path-match failures).
     *
     * `$excludeCategory` skips issues of one category: an adapter that
     * already recorded a body-decode-failure fingerprint ran the validator
     * against an absent placeholder body, so same-side body issues are
     * artifacts of the decode failure — recording them would let the
     * baseline absorb a future genuinely-empty body.
     *
     * The PSR-7 adapter folds decode failures into the result itself as
     * `parse`-keyword issues instead of recording them up front, so the
     * same artifact exclusion is derived from the issue list here: only the
     * `parse` issue of an affected category is recorded, its placeholder
     * siblings are not ({@see ViolationFingerprint::decodeFailureCategories()}).
     */
    public function recordResult(
        string $specName,
        OpenApiValidationResult $result,
        string $fallbackMethod,
        string $fallbackPath,
        ?string $excludeCategory = null,
    ): void {
        $artifactCategories = ViolationFingerprint::decodeFailureCategories($result->issues());
        foreach ($result->issues() as $issue) {
            if ($issue->category === $excludeCategory || ViolationFingerprint::isContextOnly($issue)) {
                continue;
            }
            if (
                $issue->keyword !== ViolationFingerprint::KEYWORD_PARSE &&
                isset($artifactCategories[$issue->category])
            ) {
                continue;
            }
            $this->record(ViolationFingerprint::fromIssue($specName, $issue, $fallbackMethod, $fallbackPath));
        }
    }

    public function baseline(): ViolationBaseline
    {
        return $this->baseline;
    }
}
