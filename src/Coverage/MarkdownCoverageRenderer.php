<?php

declare(strict_types=1);

namespace Studio\Gesso\Coverage;

use function implode;
use function round;
use function sprintf;

/**
 * @internal Output is exposed through the PHPUnit extension and merge CLI.
 *           This renderer is not part of the public PHP API.
 *
 * @phpstan-import-type CoverageResult from OpenApiCoverageTracker
 * @phpstan-import-type EndpointSummary from OpenApiCoverageTracker
 * @phpstan-import-type ResponseRow from OpenApiCoverageTracker
 */
final class MarkdownCoverageRenderer
{
    private const MARKER_ALL_COVERED = ':white_check_mark:';
    private const MARKER_PARTIAL = ':large_orange_diamond:';
    private const MARKER_SKIPPED = ':warning:';
    private const MARKER_UNCOVERED = ':x:';
    private const MARKER_REQUEST_ONLY = ':information_source:';

    /**
     * @param array<string, CoverageResult> $results
     */
    public static function render(array $results): string
    {
        if ($results === []) {
            return '';
        }

        $lines = ['## OpenAPI Contract Test Coverage', ''];

        foreach ($results as $specName => $result) {
            $endpointPct = self::percentage($result['endpointFullyCovered'], $result['endpointTotal']);
            $responsePct = self::percentage($result['responseCovered'], $result['responseTotal']);

            $lines[] = sprintf(
                '### %s — endpoints: %d/%d fully covered (%s%%)',
                $specName,
                $result['endpointFullyCovered'],
                $result['endpointTotal'],
                self::formatPercent($endpointPct),
            );
            $lines[] = '';
            $lines[] = sprintf(
                '_responses: %d/%d covered (%s%%) — %d skipped, %d uncovered, %d partial endpoints, %d uncovered endpoints_',
                $result['responseCovered'],
                $result['responseTotal'],
                self::formatPercent($responsePct),
                $result['responseSkipped'],
                $result['responseUncovered'],
                $result['endpointPartial'],
                $result['endpointUncovered'],
            );
            $lines[] = '';

            if ($result['endpoints'] === []) {
                continue;
            }

            $lines[] = '| Status | Endpoint | Responses |';
            $lines[] = '|--------|----------|-----------|';
            foreach ($result['endpoints'] as $endpoint) {
                $lines[] = sprintf(
                    '| %s | `%s` | %s |',
                    self::endpointMarker($endpoint['state']),
                    $endpoint['endpoint'],
                    self::endpointResponsesSummary($endpoint),
                );
            }
            $lines[] = '';

            $lines[] = '<details>';
            $lines[] = '<summary>Per-response detail</summary>';
            $lines[] = '';

            foreach ($result['endpoints'] as $endpoint) {
                $lines = [...$lines, ...self::renderEndpointDetail($endpoint)];
            }

            $lines[] = '</details>';
            $lines[] = '';
        }

        return implode("\n", $lines);
    }

    /**
     * @param EndpointSummary $endpoint
     *
     * @return list<string>
     */
    private static function renderEndpointDetail(array $endpoint): array
    {
        $heading = $endpoint['operationId'] !== null
            ? sprintf('#### `%s` (%s)', $endpoint['endpoint'], $endpoint['operationId'])
            : sprintf('#### `%s`', $endpoint['endpoint']);
        $lines = [$heading, ''];

        if ($endpoint['responses'] === []) {
            $lines[] = $endpoint['requestReached']
                ? '_request reached, no response definitions in spec_'
                : '_no response definitions in spec_';
            $lines[] = '';

            return $lines;
        }

        $lines[] = '| Status | Content-Type | State |';
        $lines[] = '|--------|--------------|-------|';
        foreach ($endpoint['responses'] as $row) {
            $lines[] = sprintf(
                '| %s %s | %s | %s |',
                self::responseMarker($row['state']),
                $row['statusKey'],
                $row['contentTypeKey'],
                self::responseStateLabel($row),
            );
        }

        if ($endpoint['unexpectedObservations'] !== []) {
            $lines[] = '';
            $lines[] = '_Unexpected observations (status / content-type not in spec):_';
            $lines[] = '';
            foreach ($endpoint['unexpectedObservations'] as $obs) {
                $lines[] = sprintf('- `%s` `%s`', $obs['statusKey'], $obs['contentTypeKey']);
            }
        }

        $lines[] = '';

        return $lines;
    }

    /**
     * @param EndpointSummary $endpoint
     */
    private static function endpointResponsesSummary(array $endpoint): string
    {
        if ($endpoint['totalResponseCount'] === 0) {
            return $endpoint['requestReached'] ? 'request only' : 'no spec entries';
        }

        $line = sprintf('%d/%d', $endpoint['coveredResponseCount'], $endpoint['totalResponseCount']);
        $extras = [];
        if ($endpoint['skippedResponseCount'] > 0) {
            $extras[] = sprintf('%d skipped', $endpoint['skippedResponseCount']);
        }
        $uncovered = $endpoint['totalResponseCount']
            - $endpoint['coveredResponseCount']
            - $endpoint['skippedResponseCount'];
        if ($uncovered > 0) {
            $extras[] = sprintf('%d uncovered', $uncovered);
        }

        return $extras === [] ? $line : sprintf('%s (%s)', $line, implode(', ', $extras));
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

    /**
     * @param ResponseRow $row
     */
    private static function responseStateLabel(array $row): string
    {
        return match ($row['state']) {
            ResponseCoverageState::Validated => sprintf('validated (%d hits)', $row['hits']),
            ResponseCoverageState::Skipped => $row['skipReason'] !== null
                ? sprintf('skipped (%s)', $row['skipReason'])
                : 'skipped',
            ResponseCoverageState::Uncovered => 'uncovered',
        };
    }

    private static function percentage(int $covered, int $total): float|int
    {
        return $total > 0 ? round($covered / $total * 100, 1) : 0;
    }

    private static function formatPercent(float|int $value): string
    {
        return (string) $value;
    }
}
