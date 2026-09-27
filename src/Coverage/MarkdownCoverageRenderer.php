<?php

declare(strict_types=1);

namespace Studio\Gesso\Coverage;

use Studio\Gesso\Internal\CoverageTotals;

use function array_keys;
use function array_unique;
use function implode;
use function sprintf;
use function str_replace;

/**
 * @internal Output is exposed through the PHPUnit extension and merge CLI.
 *           This renderer is not part of the public PHP API.
 *
 * @phpstan-import-type CoverageResult from OpenApiCoverageTracker
 * @phpstan-import-type EndpointSummary from OpenApiCoverageTracker
 * @phpstan-import-type ResponseRow from OpenApiCoverageTracker
 * @phpstan-import-type SdkExerciseCoverageResult from SdkExerciseCoverageReportBuilder
 */
final class MarkdownCoverageRenderer
{
    /** @see CoverageTotals::marker() */
    private const MARKERS = [
        'allCovered' => ':white_check_mark:',
        'partial' => ':large_orange_diamond:',
        'skipped' => ':warning:',
        'uncovered' => ':x:',
        'requestOnly' => ':information_source:',
    ];

    /**
     * @param array<string, CoverageResult> $results
     * @param array<string, SdkExerciseCoverageResult> $sdkResults
     */
    public static function render(array $results, array $sdkResults = []): string
    {
        if ($results === [] && $sdkResults === []) {
            return '';
        }

        $lines = ['## OpenAPI Contract Test Coverage', ''];

        $specNames = array_unique([...array_keys($results), ...array_keys($sdkResults)]);
        foreach ($specNames as $specName) {
            $result = $results[$specName] ?? null;
            if ($result === null) {
                $lines[] = sprintf('### %s', $specName);
                $lines[] = '';
            } else {
                $endpointPct = CoverageTotals::percentage($result['endpointFullyCovered'], $result['endpointTotal']);
                $responsePct = CoverageTotals::percentage($result['responseCovered'], $result['responseTotal']);

                $lines[] = sprintf(
                    '### %s — endpoints: %d/%d fully covered (%s%%)',
                    $specName,
                    $result['endpointFullyCovered'],
                    $result['endpointTotal'],
                    $endpointPct,
                );
                $lines[] = '';
                $lines[] = sprintf(
                    '_responses: %d/%d covered (%s%%) — %d skipped, %d uncovered, %d partial endpoints, %d uncovered endpoints_',
                    $result['responseCovered'],
                    $result['responseTotal'],
                    $responsePct,
                    $result['responseSkipped'],
                    $result['responseUncovered'],
                    $result['endpointPartial'],
                    $result['endpointUncovered'],
                );
                $lines[] = '';

                if ($result['endpoints'] !== []) {
                    $lines[] = '| Status | Endpoint | Responses |';
                    $lines[] = '|--------|----------|-----------|';
                    foreach ($result['endpoints'] as $endpoint) {
                        $lines[] = sprintf(
                            '| %s | `%s` | %s |',
                            CoverageTotals::marker($endpoint['state'], self::MARKERS),
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
            }

            if (isset($sdkResults[$specName])) {
                $lines = [...$lines, ...self::renderSdkExercise($sdkResults[$specName])];
            }
        }

        return implode("\n", $lines);
    }

    /**
     * @param SdkExerciseCoverageResult $result
     *
     * @return list<string>
     */
    private static function renderSdkExercise(array $result): array
    {
        $lines = [
            '#### SDK response schema exercise',
            '',
            sprintf(
                '_SDK responses: %d/%d exercised (%s%%) — %d unexercised_',
                $result['responseExercised'],
                $result['responseTotal'],
                CoverageTotals::percentage($result['responseExercised'], $result['responseTotal']),
                $result['responseUnexercised'],
            ),
            '',
            '| Status | Endpoint | Response | Content-Type | State |',
            '|--------|----------|----------|--------------|-------|',
        ];
        foreach ($result['responses'] as $row) {
            $lines[] = sprintf(
                '| %s | `%s` | %s | %s | %s |',
                $row['exercised'] ? self::MARKERS['allCovered'] : self::MARKERS['uncovered'],
                self::escapeCell($row['endpoint']),
                self::escapeCell($row['statusKey']),
                self::escapeCell($row['contentTypeKey']),
                $row['exercised'] ? sprintf('exercised (%d hits)', $row['hits']) : 'unexercised',
            );
        }
        $lines[] = '';

        if ($result['unexpectedObservations'] !== []) {
            $lines[] = '_Unexpected SDK exercise observations (not eligible in live spec):_';
            $lines[] = '';
            foreach ($result['unexpectedObservations'] as $row) {
                $lines[] = sprintf(
                    '- `%s` `%s` `%s` (%d hits)',
                    $row['endpoint'],
                    $row['statusKey'],
                    $row['contentTypeKey'],
                    $row['hits'],
                );
            }
            $lines[] = '';
        }

        return $lines;
    }

    private static function escapeCell(string $value): string
    {
        return str_replace(['|', "\n", "\r"], ['\\|', '<br>', ''], $value);
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
                CoverageTotals::marker($row['state'], self::MARKERS),
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
}
