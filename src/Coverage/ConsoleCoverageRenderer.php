<?php

declare(strict_types=1);

namespace Studio\Gesso\Coverage;

use const STR_PAD_RIGHT;

use Studio\Gesso\PHPUnit\ConsoleOutput;

use function round;
use function sprintf;
use function str_pad;
use function str_repeat;

/**
 * @internal Output is exposed through the PHPUnit extension and merge CLI.
 *           This renderer is not part of the public PHP API.
 *
 * @phpstan-import-type CoverageResult from OpenApiCoverageTracker
 * @phpstan-import-type EndpointSummary from OpenApiCoverageTracker
 * @phpstan-import-type ResponseRow from OpenApiCoverageTracker
 */
final class ConsoleCoverageRenderer
{
    private const MARKER_ALL_COVERED = '✓';
    private const MARKER_PARTIAL = '◐';
    private const MARKER_SKIPPED = '⚠';
    private const MARKER_UNCOVERED = '✗';
    private const MARKER_REQUEST_ONLY = '·';

    /**
     * @param array<string, CoverageResult> $results
     */
    public static function render(array $results, ConsoleOutput $consoleOutput = ConsoleOutput::DEFAULT): string
    {
        if ($results === []) {
            return '';
        }

        $output = "\n\n";
        $output .= "OpenAPI Contract Test Coverage\n";
        $output .= str_repeat('=', 50) . "\n";

        foreach ($results as $spec => $result) {
            if ($consoleOutput === ConsoleOutput::ACTIVE_ONLY && !self::specHasActivity($result)) {
                $output .= sprintf(
                    "\n[%s] no test activity (%d endpoints, %d responses in spec)\n",
                    $spec,
                    $result['endpointTotal'],
                    $result['responseTotal'],
                );

                continue;
            }

            $endpointPct = self::percentage($result['endpointFullyCovered'], $result['endpointTotal']);
            $responsePct = self::percentage($result['responseCovered'], $result['responseTotal']);

            $output .= sprintf(
                "\n[%s] endpoints: %d/%d fully covered (%s%%), %d partial, %d uncovered\n",
                $spec,
                $result['endpointFullyCovered'],
                $result['endpointTotal'],
                $endpointPct,
                $result['endpointPartial'],
                $result['endpointUncovered'],
            );
            $output .= sprintf(
                "        responses: %d/%d covered (%s%%), %d skipped, %d uncovered\n",
                $result['responseCovered'],
                $result['responseTotal'],
                $responsePct,
                $result['responseSkipped'],
                $result['responseUncovered'],
            );
            $output .= str_repeat('-', 50) . "\n";
            $output .= "Legend: ✓=validated  ⚠=skipped  ✗=uncovered  ◐=partial  ·=request-only  *=any/no content-type\n";

            $output .= self::renderEndpoints($result['endpoints'], $consoleOutput);
        }

        $output .= "\n";

        return $output;
    }

    /**
     * A spec is "active" when at least one validated/skipped response was
     * recorded, or any endpoint resolved to the `RequestOnly` bucket — see
     * {@see OpenApiCoverageTracker::deriveEndpointState()}
     * for the full definition (request hook fired, or only unexpected
     * observations recorded). Used by ACTIVE_ONLY mode to collapse specs
     * that no test in this run touched.
     *
     * Counts only declared-endpoint activity. Recordings whose endpoint key
     * is absent from the live spec (e.g. an orphan in a paratest sidecar
     * after a mid-run spec edit) are dropped by `computeCoverage` and so do
     * not flip a spec to active here either.
     *
     * @param CoverageResult $result
     */
    private static function specHasActivity(array $result): bool
    {
        return $result['responseCovered'] > 0 ||
            $result['responseSkipped'] > 0 ||
            $result['endpointRequestOnly'] > 0;
    }

    /**
     * @param list<EndpointSummary> $endpoints
     */
    private static function renderEndpoints(array $endpoints, ConsoleOutput $mode): string
    {
        if ($endpoints === []) {
            return '';
        }

        $output = '';
        foreach ($endpoints as $endpoint) {
            // DEFAULT mode renders one line per endpoint with no sub-rows.
            // ALL renders sub-rows for every endpoint. UNCOVERED_ONLY only
            // shows sub-rows when the endpoint isn't all-covered, so a
            // green run stays compact. ACTIVE_ONLY only reaches this branch
            // for active specs, and renders the same one-line-per-endpoint
            // shape as DEFAULT (inactive specs are collapsed upstream).
            $showSubRows = match ($mode) {
                ConsoleOutput::DEFAULT, ConsoleOutput::ACTIVE_ONLY => false,
                ConsoleOutput::ALL => true,
                ConsoleOutput::UNCOVERED_ONLY => $endpoint['state'] !== EndpointCoverageState::AllCovered,
            };

            $output .= sprintf(
                "  %s %s%s\n",
                self::endpointMarker($endpoint['state']),
                $endpoint['endpoint'],
                self::endpointSummaryTail($endpoint),
            );

            if (!$showSubRows) {
                continue;
            }

            foreach ($endpoint['responses'] as $row) {
                if ($mode === ConsoleOutput::UNCOVERED_ONLY && $row['state'] === ResponseCoverageState::Validated) {
                    continue;
                }
                $output .= sprintf(
                    "      %s %s  %s%s\n",
                    self::responseMarker($row['state']),
                    str_pad($row['statusKey'], 5, ' ', STR_PAD_RIGHT),
                    str_pad($row['contentTypeKey'], 32, ' ', STR_PAD_RIGHT),
                    self::responseTail($row),
                );
            }

            foreach ($endpoint['unexpectedObservations'] as $obs) {
                $output .= sprintf(
                    "      ! %s  %s  unexpected (not in spec)\n",
                    str_pad($obs['statusKey'], 5, ' ', STR_PAD_RIGHT),
                    str_pad($obs['contentTypeKey'], 32, ' ', STR_PAD_RIGHT),
                );
            }
        }

        return $output;
    }

    /**
     * @param EndpointSummary $endpoint
     */
    private static function endpointSummaryTail(array $endpoint): string
    {
        if ($endpoint['totalResponseCount'] === 0) {
            return $endpoint['requestReached'] ? '  (request only)' : '';
        }

        $tail = sprintf('  (%d/%d responses', $endpoint['coveredResponseCount'], $endpoint['totalResponseCount']);
        if ($endpoint['skippedResponseCount'] > 0) {
            $tail .= sprintf(', %d skipped', $endpoint['skippedResponseCount']);
        }

        return $tail . ')';
    }

    /**
     * @param ResponseRow $row
     */
    private static function responseTail(array $row): string
    {
        return match ($row['state']) {
            ResponseCoverageState::Validated => sprintf('[%d]', $row['hits']),
            ResponseCoverageState::Skipped => $row['skipReason'] !== null
                ? sprintf('skipped: %s', $row['skipReason'])
                : 'skipped',
            ResponseCoverageState::Uncovered => 'uncovered',
        };
    }

    private static function endpointMarker(EndpointCoverageState $state): string
    {
        return match ($state) {
            EndpointCoverageState::AllCovered => self::MARKER_ALL_COVERED,
            EndpointCoverageState::Partial => self::MARKER_PARTIAL,
            EndpointCoverageState::RequestOnly => self::MARKER_REQUEST_ONLY,
            EndpointCoverageState::Uncovered => self::MARKER_UNCOVERED,
        };
    }

    private static function responseMarker(ResponseCoverageState $state): string
    {
        return match ($state) {
            ResponseCoverageState::Validated => self::MARKER_ALL_COVERED,
            ResponseCoverageState::Skipped => self::MARKER_SKIPPED,
            ResponseCoverageState::Uncovered => self::MARKER_UNCOVERED,
        };
    }

    private static function percentage(int $covered, int $total): float|int
    {
        return $total > 0 ? round($covered / $total * 100, 1) : 0;
    }
}
