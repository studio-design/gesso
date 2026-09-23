<?php

declare(strict_types=1);

namespace Studio\Gesso\Coverage;

use const ENT_QUOTES;
use const ENT_SUBSTITUTE;

use Studio\Gesso\Internal\CoverageTotals;

use function array_keys;
use function array_unique;
use function hash;
use function htmlspecialchars;
use function implode;
use function sprintf;

/**
 * Render coverage results as a single self-contained HTML page for human
 * review (PR comments, CI artifact preview, ad-hoc browser inspection).
 *
 * Design choices:
 *  - Self-contained: inline CSS, no JS, no external assets. Drops cleanly as
 *    a CI artifact and renders offline.
 *  - `<details>`/`<summary>` for per-spec collapsible detail — works without
 *    JavaScript across every modern browser.
 *  - In-page anchor links navigate from the top-level endpoint list down to
 *    per-endpoint detail sections; ids are a hash of `(specName, endpoint)`,
 *    so they are fragment-safe and unique within a render.
 *  - All user-controlled strings pass through `htmlspecialchars` with
 *    `ENT_QUOTES | ENT_SUBSTITUTE` and explicit `'UTF-8'` so a hostile spec
 *    (path containing `<script>`, operationId with quotes, skip reason with
 *    ampersands) cannot inject markup or trip mojibake. `ENT_SUBSTITUTE`
 *    (not `ENT_HTML5`) is load-bearing: invalid UTF-8 byte sequences are
 *    replaced with U+FFFD instead of causing `htmlspecialchars` to silently
 *    return an empty string and dropping spec content from the report.
 *  - HTML is intentionally excluded from `GITHUB_STEP_SUMMARY` (Markdown-only
 *    by design); see {@see CoverageReportSubscriber::appendGithubStepSummary()}.
 *
 * @internal Output is exposed through the PHPUnit extension and merge CLI.
 *           This renderer is not part of the public PHP API.
 *
 * @phpstan-import-type CoverageResult from OpenApiCoverageTracker
 * @phpstan-import-type CoverageSums from CoverageTotals
 * @phpstan-import-type EndpointSummary from OpenApiCoverageTracker
 * @phpstan-import-type ResponseRow from OpenApiCoverageTracker
 * @phpstan-import-type SdkExerciseCoverageResult from SdkExerciseCoverageReportBuilder
 */
final class HtmlCoverageRenderer
{
    /**
     * Static-only utility — no instances. Matches the established
     * {@see OpenApiCoverageTracker} pattern.
     */
    private function __construct() {}

    /**
     * @param array<string, CoverageResult> $results
     * @param array<string, SdkExerciseCoverageResult> $sdkResults
     *
     * @return string Empty string when `$results` is empty so callers can
     *                short-circuit a no-coverage run; otherwise a full HTML
     *                document terminated by a trailing newline.
     */
    public static function render(array $results, array $sdkResults = []): string
    {
        if ($results === [] && $sdkResults === []) {
            return '';
        }

        $totals = CoverageTotals::sum($results);

        $lines = [
            '<!DOCTYPE html>',
            '<html lang="en">',
            '<head>',
            '<meta charset="UTF-8">',
            '<meta name="viewport" content="width=device-width, initial-scale=1">',
            '<title>OpenAPI Contract Test Coverage</title>',
            '<style>' . self::stylesheet() . '</style>',
            '</head>',
            '<body>',
            '<header class="page-header">',
            '<h1>OpenAPI Contract Test Coverage</h1>',
            self::renderAggregateSummary($totals),
            self::renderSdkAggregateSummary($sdkResults),
            '</header>',
        ];

        $specNames = array_unique([...array_keys($results), ...array_keys($sdkResults)]);
        foreach ($specNames as $specName) {
            $lines[] = self::renderSpec($specName, $results[$specName] ?? null, $sdkResults[$specName] ?? null);
        }

        $lines[] = '</body>';
        $lines[] = '</html>';

        return implode("\n", $lines) . "\n";
    }

    /**
     * @param CoverageSums $totals
     */
    private static function renderAggregateSummary(array $totals): string
    {
        $endpointPct = CoverageTotals::percentage($totals['endpointFullyCovered'], $totals['endpointTotal']);
        $responsePct = CoverageTotals::percentage($totals['responseCovered'], $totals['responseTotal']);

        return sprintf(
            '<div class="aggregate">'
            . '<p class="metric"><strong>%d / %d</strong> endpoints fully covered (%s%%)</p>'
            . '<p class="metric"><strong>%d / %d</strong> responses covered (%s%%)</p>'
            . '<p class="meta">%d skipped, %d uncovered, %d partial endpoints, %d uncovered endpoints, %d request-only</p>'
            . '</div>',
            $totals['endpointFullyCovered'],
            $totals['endpointTotal'],
            $endpointPct,
            $totals['responseCovered'],
            $totals['responseTotal'],
            $responsePct,
            $totals['responseSkipped'],
            $totals['responseUncovered'],
            $totals['endpointPartial'],
            $totals['endpointUncovered'],
            $totals['endpointRequestOnly'],
        );
    }

    /** @param array<string, SdkExerciseCoverageResult> $sdkResults */
    private static function renderSdkAggregateSummary(array $sdkResults): string
    {
        if ($sdkResults === []) {
            return '';
        }

        $total = 0;
        $exercised = 0;
        foreach ($sdkResults as $result) {
            $total += $result['responseTotal'];
            $exercised += $result['responseExercised'];
        }

        return sprintf(
            '<div class="aggregate sdk-aggregate"><p class="metric"><strong>%d / %d</strong> SDK responses exercised (%s%%)</p></div>',
            $exercised,
            $total,
            CoverageTotals::percentage($exercised, $total),
        );
    }

    /**
     * @param null|CoverageResult $result
     * @param null|SdkExerciseCoverageResult $sdkResult
     */
    private static function renderSpec(string $specName, ?array $result, ?array $sdkResult): string
    {
        $lines = [
            '<section class="spec">',
            sprintf('<h2>%s</h2>', self::escape($specName)),
        ];

        if ($result !== null) {
            $endpointPct = CoverageTotals::percentage($result['endpointFullyCovered'], $result['endpointTotal']);
            $responsePct = CoverageTotals::percentage($result['responseCovered'], $result['responseTotal']);
            $lines[] = sprintf(
                '<p class="spec-summary">endpoints: %d / %d fully covered (%s%%) — responses: %d / %d covered (%s%%)</p>',
                $result['endpointFullyCovered'],
                $result['endpointTotal'],
                $endpointPct,
                $result['responseCovered'],
                $result['responseTotal'],
                $responsePct,
            );

            if ($result['endpoints'] !== []) {
                // The `endpoint-` prefix keeps ids clear of browser-reserved
                // anchors such as `top`.
                $anchors = [];
                foreach ($result['endpoints'] as $endpoint) {
                    $anchors[] = 'endpoint-' . hash('xxh128', $specName . "\0" . $endpoint['endpoint']);
                }

                $lines[] = self::renderEndpointList($result['endpoints'], $anchors);
                foreach ($result['endpoints'] as $i => $endpoint) {
                    $lines[] = self::renderEndpointDetail($endpoint, $anchors[$i]);
                }
            }
        }

        if ($sdkResult !== null) {
            $lines[] = self::renderSdkExercise($sdkResult);
        }

        $lines[] = '</section>';

        return implode("\n", $lines);
    }

    /** @param SdkExerciseCoverageResult $result */
    private static function renderSdkExercise(array $result): string
    {
        $lines = [
            '<section class="sdk-exercise">',
            '<h3>SDK response schema exercise</h3>',
            sprintf(
                '<p class="sdk-summary">SDK responses: %d / %d exercised (%s%%) — %d unexercised</p>',
                $result['responseExercised'],
                $result['responseTotal'],
                CoverageTotals::percentage($result['responseExercised'], $result['responseTotal']),
                $result['responseUnexercised'],
            ),
            '<table class="sdk-responses">',
            '<thead><tr><th>Endpoint</th><th>Operation</th><th>Status</th><th>Content type</th><th>State</th><th>Hits</th></tr></thead>',
            '<tbody>',
        ];
        foreach ($result['responses'] as $row) {
            $lines[] = sprintf(
                '<tr class="state-%s"><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%d</td></tr>',
                $row['exercised'] ? 'exercised' : 'unexercised',
                self::escape($row['endpoint']),
                $row['operationId'] !== null ? self::escape($row['operationId']) : '',
                self::escape($row['statusKey']),
                self::escape($row['contentTypeKey']),
                $row['exercised'] ? 'exercised' : 'unexercised',
                $row['hits'],
            );
        }
        $lines[] = '</tbody></table>';

        if ($result['unexpectedObservations'] !== []) {
            $lines[] = '<p class="unexpected-heading">Unexpected SDK exercise observations</p>';
            $lines[] = '<table class="unexpected"><thead><tr><th>Endpoint</th><th>Status</th><th>Content type</th><th>Hits</th></tr></thead><tbody>';
            foreach ($result['unexpectedObservations'] as $row) {
                $lines[] = sprintf(
                    '<tr><td>%s</td><td>%s</td><td>%s</td><td>%d</td></tr>',
                    self::escape($row['endpoint']),
                    self::escape($row['statusKey']),
                    self::escape($row['contentTypeKey']),
                    $row['hits'],
                );
            }
            $lines[] = '</tbody></table>';
        }
        $lines[] = '</section>';

        return implode("\n", $lines);
    }

    /**
     * @param list<EndpointSummary> $endpoints
     * @param list<string> $anchors Pre-resolved anchor IDs, one per endpoint
     *                              (parallel to `$endpoints`).
     */
    private static function renderEndpointList(array $endpoints, array $anchors): string
    {
        $lines = ['<ul class="endpoint-list">'];
        foreach ($endpoints as $i => $endpoint) {
            $lines[] = sprintf(
                '<li class="state-%s"><a href="#%s">%s</a> <span class="state-label">%s</span></li>',
                self::escape($endpoint['state']->value),
                self::escape($anchors[$i]),
                self::escape($endpoint['endpoint']),
                self::escape($endpoint['state']->value),
            );
        }
        $lines[] = '</ul>';

        return implode("\n", $lines);
    }

    /**
     * @param EndpointSummary $endpoint
     */
    private static function renderEndpointDetail(array $endpoint, string $anchor): string
    {
        $stateClass = self::escape($endpoint['state']->value);

        $lines = [
            sprintf(
                '<details id="%s" class="endpoint state-%s">',
                self::escape($anchor),
                $stateClass,
            ),
            sprintf(
                '<summary><code>%s</code> — <span class="state-label">%s</span>%s</summary>',
                self::escape($endpoint['endpoint']),
                $stateClass,
                $endpoint['operationId'] !== null
                    ? sprintf(' <em class="op-id">%s</em>', self::escape($endpoint['operationId']))
                    : '',
            ),
        ];

        if ($endpoint['responses'] !== []) {
            $lines[] = '<table class="responses">';
            $lines[] = '<thead><tr><th>Status</th><th>Content type</th><th>State</th><th>Hits</th><th>Skip reason</th></tr></thead>';
            $lines[] = '<tbody>';
            foreach ($endpoint['responses'] as $row) {
                $lines[] = sprintf(
                    '<tr class="state-%s"><td>%s</td><td>%s</td><td>%s</td><td>%d</td><td>%s</td></tr>',
                    self::escape($row['state']->value),
                    self::escape($row['statusKey']),
                    self::escape($row['contentTypeKey']),
                    self::escape($row['state']->value),
                    $row['hits'],
                    $row['skipReason'] !== null ? self::escape($row['skipReason']) : '',
                );
            }
            $lines[] = '</tbody>';
            $lines[] = '</table>';
        }

        if ($endpoint['unexpectedObservations'] !== []) {
            $lines[] = '<p class="unexpected-heading">Unexpected observations</p>';
            $lines[] = '<table class="unexpected">';
            $lines[] = '<thead><tr><th>Status</th><th>Content type</th></tr></thead>';
            $lines[] = '<tbody>';
            foreach ($endpoint['unexpectedObservations'] as $obs) {
                $lines[] = sprintf(
                    '<tr><td>%s</td><td>%s</td></tr>',
                    self::escape($obs['statusKey']),
                    self::escape($obs['contentTypeKey']),
                );
            }
            $lines[] = '</tbody>';
            $lines[] = '</table>';
        }

        $lines[] = '</details>';

        return implode("\n", $lines);
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private static function stylesheet(): string
    {
        // Kept terse intentionally — the goal is readable CI output, not a
        // design system. The `.state-<value>` selectors below must mirror
        // the enum case values from {@see EndpointCoverageState} and
        // {@see ResponseCoverageState}; renaming a case requires updating
        // the matching selector here. The
        // `every_enum_case_has_a_matching_state_class` test pins this.
        return implode('', [
            'body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;color:#222;margin:0;padding:2rem;max-width:1100px;line-height:1.5;}',
            'h1{margin-top:0;}',
            '.page-header{border-bottom:1px solid #ddd;padding-bottom:1rem;margin-bottom:2rem;}',
            '.aggregate .metric{font-size:1.1rem;margin:0.25rem 0;}',
            '.aggregate .meta{color:#666;font-size:0.9rem;}',
            '.spec{margin-bottom:2rem;}',
            '.spec-summary{color:#444;}',
            '.endpoint-list{list-style:none;padding:0;}',
            '.endpoint-list li{padding:0.25rem 0.5rem;margin:0.1rem 0;border-radius:4px;}',
            '.endpoint-list li a{text-decoration:none;color:#0366d6;}',
            '.endpoint-list li a:hover{text-decoration:underline;}',
            '.state-label{font-size:0.8rem;text-transform:uppercase;color:#666;margin-left:0.5rem;}',
            '.state-all-covered,.state-validated{border-left:4px solid #28a745;padding-left:0.5rem;}',
            '.state-partial{border-left:4px solid #f0ad4e;padding-left:0.5rem;}',
            '.state-uncovered{border-left:4px solid #d73a49;padding-left:0.5rem;}',
            '.state-request-only{border-left:4px solid #6f42c1;padding-left:0.5rem;}',
            '.state-skipped{border-left:4px solid #aaa;padding-left:0.5rem;}',
            '.endpoint{margin:0.5rem 0;padding:0.5rem;border:1px solid #eee;border-radius:4px;}',
            '.endpoint summary{cursor:pointer;}',
            '.endpoint .op-id{color:#666;}',
            'table.responses,table.unexpected{border-collapse:collapse;margin:0.75rem 0;width:100%;}',
            'table.responses th,table.responses td,table.unexpected th,table.unexpected td{border:1px solid #eee;padding:0.4rem 0.75rem;text-align:left;font-size:0.9rem;}',
            'table.responses th,table.unexpected th{background:#f6f8fa;font-weight:600;}',
            '.unexpected-heading{margin-top:1rem;font-weight:600;color:#d73a49;}',
        ]);
    }
}
