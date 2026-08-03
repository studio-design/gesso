<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit\Coverage;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\Gesso\Coverage\EndpointCoverageState;
use Studio\Gesso\Coverage\HtmlCoverageRenderer;
use Studio\Gesso\Coverage\OpenApiCoverageTracker;
use Studio\Gesso\Coverage\ResponseCoverageState;
use Studio\Gesso\Tests\Helpers\SdkExerciseCoverageResultFixture;

use function array_unique;
use function explode;
use function preg_match_all;
use function sort;
use function substr_count;

/**
 * @phpstan-import-type EndpointSummary from OpenApiCoverageTracker
 */
class HtmlCoverageRendererTest extends TestCase
{
    #[Test]
    public function render_returns_empty_string_for_empty_results(): void
    {
        // Matches the contract of MarkdownCoverageRenderer / JUnitCoverageRenderer /
        // JsonCoverageRenderer — callers short-circuit a no-coverage run.
        $this->assertSame('', HtmlCoverageRenderer::render([]));
    }

    #[Test]
    public function render_emits_html5_doctype_and_utf8_meta(): void
    {
        $html = $this->renderOneValidated();

        $this->assertStringStartsWith('<!DOCTYPE html>', $html);
        $this->assertStringContainsString('<meta charset="UTF-8">', $html);
        $this->assertStringContainsString('<title>', $html);
    }

    #[Test]
    public function render_inlines_all_css_with_no_external_resources(): void
    {
        // The HTML must be a single self-contained file — no external CSS/JS
        // links — so CI artifact upload + offline review work without
        // additional configuration.
        $html = $this->renderOneValidated();

        $this->assertStringContainsString('<style>', $html);
        $this->assertStringNotContainsString('<link', $html);
        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('href="http', $html);
        $this->assertStringNotContainsString('src="http', $html);
    }

    #[Test]
    public function render_escapes_special_characters_in_paths_and_operation_ids(): void
    {
        // htmlspecialchars(ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') must be
        // applied so a hostile spec value cannot inject markup. The path
        // template braces and ampersands are realistic OpenAPI content.
        $html = $this->renderOne(
            'front',
            self::endpoint('GET /v1/pets/<script>alert(1)</script>', 'uncovered', responses: [
                self::row('200', 'application/json', 'uncovered'),
            ], totalResponseCount: 1),
            endpointTotal: 1,
            endpointUncovered: 1,
            responseTotal: 1,
            responseUncovered: 1,
        );

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
    }

    #[Test]
    public function render_escapes_skip_reasons(): void
    {
        $html = $this->renderOne(
            'front',
            self::endpoint('DELETE /v1/pets/{petId}', 'partial', responses: [
                self::row('503', 'application/json', 'skipped', hits: 1, skipReason: 'pattern <foo>&bar'),
            ], skippedResponseCount: 1, totalResponseCount: 1),
            endpointTotal: 1,
            endpointPartial: 1,
            responseTotal: 1,
            responseSkipped: 1,
        );

        $this->assertStringNotContainsString('<foo>&bar', $html);
        $this->assertStringContainsString('&lt;foo&gt;&amp;bar', $html);
    }

    #[Test]
    public function render_escapes_operation_id(): void
    {
        // operationId is spec-author-controlled; an OpenAPI document with a
        // hostile operationId must not inject markup. Pinning the escape on
        // this path explicitly because the existing escape test for paths
        // happens to not exercise this branch.
        $html = $this->renderOne(
            'front',
            self::endpoint(
                'GET /v1/pets',
                'all-covered',
                operationId: 'list<script>Pets</script>',
                responses: [self::row('200', 'application/json', 'validated', hits: 1)],
                coveredResponseCount: 1,
                totalResponseCount: 1,
            ),
            endpointTotal: 1,
            endpointFullyCovered: 1,
            responseTotal: 1,
            responseCovered: 1,
        );

        $this->assertStringNotContainsString('<script>Pets</script>', $html);
        $this->assertStringContainsString('list&lt;script&gt;Pets&lt;/script&gt;', $html);
    }

    #[Test]
    public function render_escapes_response_status_and_content_type(): void
    {
        // Content types in OpenAPI specs can carry quoted parameters; status
        // keys can be spec-author-defined ranges. Both must escape.
        $html = $this->renderOne(
            'front',
            self::endpoint('GET /v1/pets', 'all-covered', responses: [
                self::row('2<x>0', 'application/<bad>+json', 'validated', hits: 1),
            ], coveredResponseCount: 1, totalResponseCount: 1),
            endpointTotal: 1,
            endpointFullyCovered: 1,
            responseTotal: 1,
            responseCovered: 1,
        );

        $this->assertStringNotContainsString('<x>', $html);
        $this->assertStringNotContainsString('<bad>', $html);
        $this->assertStringContainsString('2&lt;x&gt;0', $html);
        $this->assertStringContainsString('application/&lt;bad&gt;+json', $html);
    }

    #[Test]
    public function render_escapes_unexpected_observation_fields(): void
    {
        // unexpected_observations come from runtime traffic that did not
        // match any declared response — values are attacker-controllable in
        // a misbehaving backend, so escape applies here too.
        $html = $this->renderOne(
            'front',
            self::endpoint('POST /v1/pets', 'partial', responses: [
                self::row('201', 'application/json', 'validated', hits: 1),
            ], coveredResponseCount: 1, totalResponseCount: 1, unexpectedObservations: [
                ['statusKey' => '4<x>8', 'contentTypeKey' => 'application/<bad>+json'],
            ]),
            endpointTotal: 1,
            endpointFullyCovered: 1,
            responseTotal: 1,
            responseCovered: 1,
        );

        $this->assertStringNotContainsString('<x>', $html);
        $this->assertStringNotContainsString('<bad>', $html);
        $this->assertStringContainsString('4&lt;x&gt;8', $html);
        $this->assertStringContainsString('application/&lt;bad&gt;+json', $html);
    }

    #[Test]
    public function render_escapes_spec_name_in_heading(): void
    {
        // Spec keys come from PHPUnit Extension config — typically clean,
        // but the renderer's threat model covers "all user-controlled
        // strings", so a hostile spec name must not break the page.
        $results = [
            'front<script>' => self::coverage(
                endpoints: [
                    self::endpoint('GET /v1/pets', 'all-covered', responses: [
                        self::row('200', 'application/json', 'validated', hits: 1),
                    ], coveredResponseCount: 1, totalResponseCount: 1),
                ],
                endpointTotal: 1,
                endpointFullyCovered: 1,
                responseTotal: 1,
                responseCovered: 1,
            ),
        ];

        $html = HtmlCoverageRenderer::render($results);

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('front&lt;script&gt;', $html);
    }

    #[Test]
    public function render_includes_aggregate_summary_with_counts(): void
    {
        // Tight assertions on the aggregate header so a percent-formula or
        // argument-order regression in renderAggregateSummary() trips the
        // test. The renderOneValidated() fixture has 1/1 endpoints fully
        // covered and 1/1 responses covered.
        $html = $this->renderOneValidated();

        $this->assertStringContainsString('<strong>1 / 1</strong> endpoints fully covered (100%)', $html);
        $this->assertStringContainsString('<strong>1 / 1</strong> responses covered (100%)', $html);
    }

    #[Test]
    public function render_handles_endpoint_with_no_responses_or_unexpected(): void
    {
        // A request-only endpoint with neither declared responses nor
        // unexpected observations must still close its <details> block
        // cleanly. Guards the empty-list branches in renderEndpointDetail.
        $html = $this->renderOne(
            'front',
            self::endpoint('GET /v1/health', 'request-only', requestReached: true),
            endpointTotal: 1,
            endpointRequestOnly: 1,
        );

        $this->assertStringContainsString('<details', $html);
        $this->assertStringContainsString('</details>', $html);
        $this->assertSame(
            substr_count($html, '<details'),
            substr_count($html, '</details>'),
            'unbalanced <details> tags',
        );
    }

    #[Test]
    public function render_handles_spec_with_zero_endpoints(): void
    {
        // A spec loaded but with no declared endpoints (or fully filtered
        // out) should still render its <h2> + summary, not silently
        // disappear from the report.
        $html = HtmlCoverageRenderer::render([
            'empty-spec' => self::coverage(endpoints: []),
        ]);

        $this->assertStringContainsString('empty-spec', $html);
        $this->assertStringContainsString('endpoints: 0 / 0', $html);
    }

    #[Test]
    public function render_has_balanced_structural_tags(): void
    {
        // Single <head>, single <body>, single <style>, no leftover open
        // tags — catches a copy/paste accident in the template emit code.
        $html = $this->renderOneValidated();

        $this->assertSame(1, substr_count($html, '<head>'));
        $this->assertSame(1, substr_count($html, '</head>'));
        $this->assertSame(1, substr_count($html, '<body>'));
        $this->assertSame(1, substr_count($html, '</body>'));
        $this->assertSame(1, substr_count($html, '<style>'));
        $this->assertSame(1, substr_count($html, '</style>'));
    }

    #[Test]
    public function render_anchors_resolve_to_existing_targets(): void
    {
        // Every href="#X" must point at an id="X" that actually exists.
        // The list section and detail section both call into the same
        // allocator; this test pins that the two stay in sync.
        $html = $this->renderOne(
            'front',
            self::endpoint('GET /v1/pets', 'all-covered', responses: [
                self::row('200', 'application/json', 'validated', hits: 1),
            ], coveredResponseCount: 1, totalResponseCount: 1),
            endpointTotal: 1,
            endpointFullyCovered: 1,
            responseTotal: 1,
            responseCovered: 1,
        );

        preg_match_all('/id="(endpoint-[^"]+)"/', $html, $idMatches);
        preg_match_all('/href="#(endpoint-[^"]+)"/', $html, $hrefMatches);

        sort($idMatches[1]);
        sort($hrefMatches[1]);

        $this->assertNotEmpty($idMatches[1], 'renderer emitted no anchor IDs');
        $this->assertSame($idMatches[1], $hrefMatches[1], 'href / id sets diverged');
    }

    #[Test]
    public function render_disambiguates_collision_prone_anchor_keys(): void
    {
        // Two endpoints whose (specName, endpoint) tuples collapse to the
        // same kebab slug under rawurlencode + %XX→- must still produce
        // unique anchor IDs so the in-page navigation works.
        $results = [
            'front' => self::coverage(
                endpoints: [
                    self::endpoint('GET /a/b', 'all-covered', responses: [
                        self::row('200', 'application/json', 'validated', hits: 1),
                    ], coveredResponseCount: 1, totalResponseCount: 1),
                    self::endpoint('GET /a-b', 'all-covered', responses: [
                        self::row('200', 'application/json', 'validated', hits: 1),
                    ], coveredResponseCount: 1, totalResponseCount: 1),
                ],
                endpointTotal: 2,
                endpointFullyCovered: 2,
                responseTotal: 2,
                responseCovered: 2,
            ),
        ];

        $html = HtmlCoverageRenderer::render($results);

        preg_match_all('/id="(endpoint-[^"]+)"/', $html, $idMatches);
        $this->assertCount(2, $idMatches[1]);
        $this->assertCount(2, array_unique($idMatches[1]), 'anchor IDs collided across endpoints');
    }

    #[Test]
    public function every_endpoint_enum_case_has_a_matching_state_css_rule(): void
    {
        // Renderer derives `state-<value>` classes from enum case values.
        // If a new enum case is added, the stylesheet must gain a matching
        // selector — otherwise the new state silently has no styling.
        $html = $this->renderOneValidated();

        foreach (EndpointCoverageState::cases() as $case) {
            $this->assertStringContainsString(
                '.state-' . $case->value,
                $html,
                "stylesheet missing rule for endpoint state '{$case->value}'",
            );
        }
    }

    #[Test]
    public function every_response_enum_case_has_a_matching_state_css_rule(): void
    {
        $html = $this->renderOneValidated();

        foreach (ResponseCoverageState::cases() as $case) {
            $this->assertStringContainsString(
                '.state-' . $case->value,
                $html,
                "stylesheet missing rule for response state '{$case->value}'",
            );
        }
    }

    #[Test]
    public function render_emits_all_four_endpoint_state_markers(): void
    {
        // The four state CSS classes must be distinct so styling differentiates
        // them. (Don't depend on exact class names; just verify they exist as
        // distinct emitted tokens.)
        $results = [
            'front' => self::coverage(
                endpoints: [
                    self::endpoint('GET /a', 'all-covered', responses: [
                        self::row('200', 'application/json', 'validated', hits: 1),
                    ], coveredResponseCount: 1, totalResponseCount: 1),
                    self::endpoint('GET /b', 'partial', responses: [
                        self::row('200', 'application/json', 'validated', hits: 1),
                        self::row('500', 'application/json', 'uncovered'),
                    ], coveredResponseCount: 1, totalResponseCount: 2),
                    self::endpoint('GET /c', 'uncovered', responses: [
                        self::row('200', 'application/json', 'uncovered'),
                    ], totalResponseCount: 1),
                    self::endpoint('GET /d', 'request-only', requestReached: true, unexpectedObservations: [
                        ['statusKey' => '418', 'contentTypeKey' => 'application/json'],
                    ]),
                ],
                endpointTotal: 4,
                endpointFullyCovered: 1,
                endpointPartial: 1,
                endpointUncovered: 1,
                endpointRequestOnly: 1,
                responseTotal: 3,
                responseCovered: 2,
                responseUncovered: 1,
            ),
        ];

        $html = HtmlCoverageRenderer::render($results);

        $this->assertStringContainsString('all-covered', $html);
        $this->assertStringContainsString('partial', $html);
        $this->assertStringContainsString('uncovered', $html);
        $this->assertStringContainsString('request-only', $html);
    }

    #[Test]
    public function render_emits_details_summary_per_spec(): void
    {
        // <details>/<summary> gives reviewers a collapsible per-endpoint detail
        // view without needing JavaScript. Required for the "single
        // self-contained HTML, no JS" contract.
        $html = $this->renderOneValidated();

        $this->assertStringContainsString('<details', $html);
        $this->assertStringContainsString('<summary', $html);
    }

    #[Test]
    public function render_emits_anchor_targets_for_endpoint_navigation(): void
    {
        // Each endpoint section must have an id attribute so the in-page
        // endpoint list can link directly to it.
        $html = $this->renderOneValidated();

        $this->assertMatchesRegularExpression('/id="endpoint-[^"]+"/', $html);
        $this->assertMatchesRegularExpression('/href="#endpoint-[^"]+"/', $html);
    }

    #[Test]
    public function render_emits_section_per_spec(): void
    {
        // Multi-spec runs must produce one section per spec rather than
        // merging endpoints under a single header.
        $results = [
            'spec-a' => self::coverage(
                endpoints: [
                    self::endpoint('GET /a/pets', 'all-covered', responses: [
                        self::row('200', 'application/json', 'validated', hits: 1),
                    ], coveredResponseCount: 1, totalResponseCount: 1),
                ],
                endpointTotal: 1,
                endpointFullyCovered: 1,
                responseTotal: 1,
                responseCovered: 1,
            ),
            'spec-b' => self::coverage(
                endpoints: [
                    self::endpoint('GET /b/widgets', 'all-covered', responses: [
                        self::row('200', 'application/json', 'validated', hits: 1),
                    ], coveredResponseCount: 1, totalResponseCount: 1),
                ],
                endpointTotal: 1,
                endpointFullyCovered: 1,
                responseTotal: 1,
                responseCovered: 1,
            ),
        ];

        $html = HtmlCoverageRenderer::render($results);

        $this->assertStringContainsString('spec-a', $html);
        $this->assertStringContainsString('spec-b', $html);
        $this->assertStringContainsString('GET /a/pets', $html);
        $this->assertStringContainsString('GET /b/widgets', $html);
        // At minimum one <details> per spec.
        $this->assertGreaterThanOrEqual(2, substr_count($html, '<details'));
    }

    #[Test]
    public function render_emits_unexpected_observations(): void
    {
        $html = $this->renderOne(
            'front',
            self::endpoint('POST /v1/pets', 'partial', responses: [
                self::row('201', 'application/json', 'validated', hits: 1),
            ], coveredResponseCount: 1, totalResponseCount: 1, unexpectedObservations: [
                ['statusKey' => '418', 'contentTypeKey' => 'application/json'],
            ]),
            endpointTotal: 1,
            endpointFullyCovered: 1,
            responseTotal: 1,
            responseCovered: 1,
        );

        $this->assertStringContainsString('418', $html);
        $this->assertStringContainsString('application/json', $html);
    }

    #[Test]
    public function render_ends_with_closing_html_tag(): void
    {
        $html = $this->renderOneValidated();

        $this->assertStringEndsWith("</html>\n", $html);
    }

    #[Test]
    public function render_includes_escaped_sdk_response_schema_summary_and_table(): void
    {
        $html = HtmlCoverageRenderer::render(
            [],
            ['front' => SdkExerciseCoverageResultFixture::result()],
        );

        $this->assertStringContainsString('<h2>front</h2>', $html);
        $this->assertStringContainsString('<h3>SDK response schema exercise</h3>', $html);
        $this->assertStringContainsString('SDK responses: 1 / 2 exercised (50%)', $html);
        $this->assertStringContainsString('GET /v1/sdk-pets', $html);
        $this->assertStringContainsString('application/problem+json', $html);
        $this->assertStringContainsString('unexercised', $html);
        $this->assertStringContainsString('GET /v1/orphan', $html);
    }

    /**
     * @param list<array{endpoint: string, method: string, path: string, operationId: ?string, state: EndpointCoverageState, requestReached: bool, responses: list<array{statusKey: string, contentTypeKey: string, state: ResponseCoverageState, hits: int, skipReason: ?string}>, coveredResponseCount: int, skippedResponseCount: int, totalResponseCount: int, unexpectedObservations: list<array{statusKey: string, contentTypeKey: string}>}> $endpoints
     *
     * @return array{endpoints: list<array{endpoint: string, method: string, path: string, operationId: ?string, state: EndpointCoverageState, requestReached: bool, responses: list<array{statusKey: string, contentTypeKey: string, state: ResponseCoverageState, hits: int, skipReason: ?string}>, coveredResponseCount: int, skippedResponseCount: int, totalResponseCount: int, unexpectedObservations: list<array{statusKey: string, contentTypeKey: string}>}>, endpointTotal: int, endpointFullyCovered: int, endpointPartial: int, endpointUncovered: int, endpointRequestOnly: int, responseTotal: int, responseCovered: int, responseSkipped: int, responseUncovered: int}
     */
    private static function coverage(
        array $endpoints,
        int $endpointTotal = 0,
        int $endpointFullyCovered = 0,
        int $endpointPartial = 0,
        int $endpointUncovered = 0,
        int $endpointRequestOnly = 0,
        int $responseTotal = 0,
        int $responseCovered = 0,
        int $responseSkipped = 0,
        int $responseUncovered = 0,
    ): array {
        return [
            'endpoints' => $endpoints,
            'endpointTotal' => $endpointTotal,
            'endpointFullyCovered' => $endpointFullyCovered,
            'endpointPartial' => $endpointPartial,
            'endpointUncovered' => $endpointUncovered,
            'endpointRequestOnly' => $endpointRequestOnly,
            'responseTotal' => $responseTotal,
            'responseCovered' => $responseCovered,
            'responseSkipped' => $responseSkipped,
            'responseUncovered' => $responseUncovered,
        ];
    }

    /**
     * @param list<array{statusKey: string, contentTypeKey: string, state: ResponseCoverageState, hits: int, skipReason: ?string}> $responses
     * @param list<array{statusKey: string, contentTypeKey: string}> $unexpectedObservations
     *
     * @return array{endpoint: string, method: string, path: string, operationId: ?string, state: EndpointCoverageState, requestReached: bool, responses: list<array{statusKey: string, contentTypeKey: string, state: ResponseCoverageState, hits: int, skipReason: ?string}>, coveredResponseCount: int, skippedResponseCount: int, totalResponseCount: int, unexpectedObservations: list<array{statusKey: string, contentTypeKey: string}>}
     */
    private static function endpoint(
        string $endpoint,
        string $state,
        bool $requestReached = false,
        ?string $operationId = null,
        array $responses = [],
        int $coveredResponseCount = 0,
        int $skippedResponseCount = 0,
        int $totalResponseCount = 0,
        array $unexpectedObservations = [],
    ): array {
        [$method, $path] = explode(' ', $endpoint, 2);

        return [
            'endpoint' => $endpoint,
            'method' => $method,
            'path' => $path,
            'operationId' => $operationId,
            'state' => EndpointCoverageState::from($state),
            'requestReached' => $requestReached,
            'responses' => $responses,
            'coveredResponseCount' => $coveredResponseCount,
            'skippedResponseCount' => $skippedResponseCount,
            'totalResponseCount' => $totalResponseCount,
            'unexpectedObservations' => $unexpectedObservations,
        ];
    }

    /**
     * @return array{statusKey: string, contentTypeKey: string, state: ResponseCoverageState, hits: int, skipReason: ?string}
     */
    private static function row(
        string $statusKey,
        string $contentTypeKey,
        string $state,
        int $hits = 0,
        ?string $skipReason = null,
    ): array {
        return [
            'statusKey' => $statusKey,
            'contentTypeKey' => $contentTypeKey,
            'state' => ResponseCoverageState::from($state),
            'hits' => $hits,
            'skipReason' => $skipReason,
        ];
    }

    /**
     * @param EndpointSummary $endpoint
     */
    private function renderOne(
        string $specName,
        array $endpoint,
        int $endpointTotal = 0,
        int $endpointFullyCovered = 0,
        int $endpointPartial = 0,
        int $endpointUncovered = 0,
        int $endpointRequestOnly = 0,
        int $responseTotal = 0,
        int $responseCovered = 0,
        int $responseSkipped = 0,
        int $responseUncovered = 0,
    ): string {
        $results = [
            $specName => self::coverage(
                endpoints: [$endpoint],
                endpointTotal: $endpointTotal,
                endpointFullyCovered: $endpointFullyCovered,
                endpointPartial: $endpointPartial,
                endpointUncovered: $endpointUncovered,
                endpointRequestOnly: $endpointRequestOnly,
                responseTotal: $responseTotal,
                responseCovered: $responseCovered,
                responseSkipped: $responseSkipped,
                responseUncovered: $responseUncovered,
            ),
        ];

        return HtmlCoverageRenderer::render($results);
    }

    private function renderOneValidated(): string
    {
        return $this->renderOne(
            'front',
            self::endpoint(
                'GET /v1/pets',
                'all-covered',
                requestReached: true,
                operationId: 'listPets',
                responses: [
                    self::row('200', 'application/json', 'validated', hits: 3),
                ],
                coveredResponseCount: 1,
                totalResponseCount: 1,
            ),
            endpointTotal: 1,
            endpointFullyCovered: 1,
            responseTotal: 1,
            responseCovered: 1,
        );
    }
}
