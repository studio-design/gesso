<?php

declare(strict_types=1);

namespace Studio\Gesso\Baseline;

use Studio\Gesso\OpenApiValidationResult;

/**
 * Run-level recorder for baseline generation (issue #402).
 *
 * Installed by the PHPUnit extension only when the run is a baseline
 * generation run (`OPENAPI_BASELINE_GENERATE`). Unlike the tracker seams,
 * {@see current()} is nullable on purpose: a `null` collector means
 * "generate mode is off" and adapters take the normal assertion path, so
 * no lazy default instance may ever exist.
 *
 * @internal Implementation detail of the violation baseline; the
 *           `OPENAPI_BASELINE_GENERATE` env var and `baseline_file`
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

    public function record(ViolationFingerprint $fingerprint): void
    {
        $this->baseline->add($fingerprint);
    }

    /**
     * Record every issue of a failing result. The adapter's resolved method
     * and raw request path back-fill issues that carry no context of their
     * own (e.g. path-match failures).
     */
    public function recordResult(
        string $specName,
        OpenApiValidationResult $result,
        string $fallbackMethod,
        string $fallbackPath,
    ): void {
        foreach ($result->issues() as $issue) {
            $this->record(ViolationFingerprint::fromIssue($specName, $issue, $fallbackMethod, $fallbackPath));
        }
    }

    public function baseline(): ViolationBaseline
    {
        return $this->baseline;
    }
}
