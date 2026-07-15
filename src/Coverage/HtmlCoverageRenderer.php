<?php

declare(strict_types=1);

namespace Studio\Gesso\Coverage;

use const ENT_QUOTES;
use const ENT_SUBSTITUTE;

use function htmlspecialchars;
use function implode;
use function preg_replace;
use function rawurlencode;
use function round;
use function sprintf;
use function strtolower;

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
 *    per-endpoint detail sections. Anchors are deduplicated within a single
 *    render — see {@see self::renderEndpointList()} / {@see self::makeAnchorAllocator()}.
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
 * @phpstan-import-type EndpointSummary from OpenApiCoverageTracker
 * @phpstan-import-type ResponseRow from OpenApiCoverageTracker
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
     *
     * @return string Empty string when `$results` is empty so callers can
     *                short-circuit a no-coverage run; otherwise a full HTML
     *                document terminated by a trailing newline.
     */
    public static function render(array $results): string
    {
        if ($results === []) {
            return '';
        }

        $totals = self::aggregate($results);

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
            '</header>',
        ];

        $allocateAnchor = self::makeAnchorAllocator();

        foreach ($results as $specName => $result) {
            $lines[] = self::renderSpec($specName, $result, $allocateAnchor);
        }

        $lines[] = '</body>';
        $lines[] = '</html>';

        return implode("\n", $lines) . "\n";
    }

    /**
     * @param array<string, CoverageResult> $results
     *
     * @return array{
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
     */
    private static function aggregate(array $results): array
    {
        $totals = [
            'endpointTotal' => 0,
            'endpointFullyCovered' => 0,
            'endpointPartial' => 0,
            'endpointUncovered' => 0,
            'endpointRequestOnly' => 0,
            'responseTotal' => 0,
            'responseCovered' => 0,
            'responseSkipped' => 0,
            'responseUncovered' => 0,
        ];

        foreach ($results as $result) {
            $totals['endpointTotal'] += $result['endpointTotal'];
            $totals['endpointFullyCovered'] += $result['endpointFullyCovered'];
            $totals['endpointPartial'] += $result['endpointPartial'];
            $totals['endpointUncovered'] += $result['endpointUncovered'];
            $totals['endpointRequestOnly'] += $result['endpointRequestOnly'];
            $totals['responseTotal'] += $result['responseTotal'];
            $totals['responseCovered'] += $result['responseCovered'];
            $totals['responseSkipped'] += $result['responseSkipped'];
            $totals['responseUncovered'] += $result['responseUncovered'];
        }

        return $totals;
    }

    /**
     * @param array{endpointTotal: int, endpointFullyCovered: int, endpointPartial: int, endpointUncovered: int, endpointRequestOnly: int, responseTotal: int, responseCovered: int, responseSkipped: int, responseUncovered: int} $totals
     */
    private static function renderAggregateSummary(array $totals): string
    {
        $endpointPct = self::percent($totals['endpointFullyCovered'], $totals['endpointTotal']);
        $responsePct = self::percent($totals['responseCovered'], $totals['responseTotal']);

        return sprintf(
            '<div class="aggregate">'
            . '<p class="metric"><strong>%d / %d</strong> endpoints fully covered (%s%%)</p>'
            . '<p class="metric"><strong>%d / %d</strong> responses covered (%s%%)</p>'
            . '<p class="meta">%d skipped, %d uncovered, %d partial endpoints, %d uncovered endpoints, %d request-only</p>'
            . '</div>',
            $totals['endpointFullyCovered'],
            $totals['endpointTotal'],
            self::formatPercent($endpointPct),
            $totals['responseCovered'],
            $totals['responseTotal'],
            self::formatPercent($responsePct),
            $totals['responseSkipped'],
            $totals['responseUncovered'],
            $totals['endpointPartial'],
            $totals['endpointUncovered'],
            $totals['endpointRequestOnly'],
        );
    }

    /**
     * @param CoverageResult $result
     * @param callable(string, string): string $allocateAnchor
     */
    private static function renderSpec(string $specName, array $result, callable $allocateAnchor): string
    {
        $endpointPct = self::percent($result['endpointFullyCovered'], $result['endpointTotal']);
        $responsePct = self::percent($result['responseCovered'], $result['responseTotal']);

        $lines = [
            '<section class="spec">',
            sprintf('<h2>%s</h2>', self::escape($specName)),
            sprintf(
                '<p class="spec-summary">endpoints: %d / %d fully covered (%s%%) — responses: %d / %d covered (%s%%)</p>',
                $result['endpointFullyCovered'],
                $result['endpointTotal'],
                self::formatPercent($endpointPct),
                $result['responseCovered'],
                $result['responseTotal'],
                self::formatPercent($responsePct),
            ),
        ];

        if ($result['endpoints'] !== []) {
            // Resolve every anchor up front so the list and detail sections
            // emit byte-for-byte identical IDs without recomputing (which
            // would risk allocator divergence on collision-suffix runs).
            $anchors = [];
            foreach ($result['endpoints'] as $endpoint) {
                $anchors[] = $allocateAnchor($specName, $endpoint['endpoint']);
            }

            $lines[] = self::renderEndpointList($result['endpoints'], $anchors);
            foreach ($result['endpoints'] as $i => $endpoint) {
                $lines[] = self::renderEndpointDetail($endpoint, $anchors[$i]);
            }
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

    /**
     * Build a closure that allocates unique anchor IDs across one render.
     *
     * The slug pipeline is `rawurlencode` → `%XX → -` → `strtolower`. The
     * two-step encode/collapse yields a readable kebab-case fragment instead
     * of leaving `%XX` sequences in the URL bar; `rawurlencode` is the
     * cheapest way to enumerate "every character that's not safe in a
     * fragment". The collapse is lossy by construction, so two distinct
     * `(specName, endpoint)` inputs can map to the same slug (e.g. a slash
     * vs. a literal `-`). When that happens, suffix with `-2`, `-3`, … so
     * each `<details id="…">` stays unique within the document.
     *
     * `?? $slug` guards against a future `preg_replace` failure (regex error
     * or PCRE backtracking limit) silently producing empty anchor IDs.
     *
     * The `"endpoint-"` prefix prevents collisions with browser-reserved
     * anchors (e.g. `top`).
     *
     * @return callable(string, string): string Receives `(specName, endpoint)`
     *                                          and returns a unique anchor ID
     *                                          for this render.
     */
    private static function makeAnchorAllocator(): callable
    {
        /** @var array<string, int> $seen */
        $seen = [];

        return static function (string $specName, string $endpoint) use (&$seen): string {
            $encoded = rawurlencode($specName . '-' . $endpoint);
            $slug = preg_replace('/%[0-9A-Fa-f]{2}/', '-', $encoded) ?? $encoded;
            $base = 'endpoint-' . strtolower($slug);

            if (!isset($seen[$base])) {
                $seen[$base] = 1;

                return $base;
            }

            $seen[$base]++;

            return $base . '-' . $seen[$base];
        };
    }

    private static function percent(int $covered, int $total): float
    {
        if ($total === 0) {
            return 0.0;
        }

        return $covered / $total * 100.0;
    }

    private static function formatPercent(float $pct): string
    {
        return (string) round($pct, 1);
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
