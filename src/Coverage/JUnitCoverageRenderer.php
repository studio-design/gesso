<?php

declare(strict_types=1);

namespace Studio\Gesso\Coverage;

use DOMDocument;
use DOMElement;
use RuntimeException;

use function implode;
use function sprintf;

/**
 * Render coverage results as a JUnit XML document for CI dashboards (GitLab,
 * Jenkins, SonarQube, Bitrise, CircleCI test-report tabs).
 *
 * Mapping from coverage state to JUnit elements:
 *  - {@see ResponseCoverageState::Validated}: `<testcase>` with no child element (CI green)
 *  - {@see ResponseCoverageState::Skipped}:   `<testcase>` with `<skipped>` (CI yellow)
 *  - {@see ResponseCoverageState::Uncovered}: `<testcase>` with `<failure type="UncoveredResponse">` (CI red)
 *  - Unexpected observation (status / content-type not in spec): `<testcase>` with `<failure type="UnexpectedObservation">`
 *  - Endpoint with neither declared responses nor unexpected observations recorded:
 *    one synthetic `<testcase>` with `<skipped>` so the structural gap stays visible
 *    in CI dashboards instead of the endpoint disappearing entirely.
 *
 * Structural decisions:
 *  - Wrap in `<testsuites>` root even for one spec (some Jenkins-strict
 *    parsers and CI integrations reject a bare `<testsuite>` at root).
 *  - `classname="openapi.coverage.{specName}"` for SonarQube tree-view compatibility.
 *  - Emit `time="0"` on every element (some parsers warn or refuse to parse
 *    a `<testcase>` / `<testsuite>` without a `time` attribute).
 *  - Build via {@see DOMDocument} so attribute / text escaping is automatic —
 *    avoids the entire class of hand-rolled-concat XML bugs.
 *
 * @internal Output is exposed through the PHPUnit extension and merge CLI.
 *           This renderer is not part of the public PHP API.
 *
 * @phpstan-import-type CoverageResult from OpenApiCoverageTracker
 * @phpstan-import-type EndpointSummary from OpenApiCoverageTracker
 * @phpstan-import-type ResponseRow from OpenApiCoverageTracker
 */
final class JUnitCoverageRenderer
{
    private const ROOT_NAME = 'openapi-contract-coverage';
    private const CLASSNAME_PREFIX = 'openapi.coverage.';

    /**
     * @param array<string, CoverageResult> $results
     */
    public static function render(array $results): string
    {
        if ($results === []) {
            return '';
        }

        $doc = new DOMDocument('1.0', 'UTF-8');
        $doc->formatOutput = true;

        $root = $doc->createElement('testsuites');
        $root->setAttribute('name', self::ROOT_NAME);
        $root->setAttribute('time', '0');
        $doc->appendChild($root);

        $totalTests = 0;
        $totalFailures = 0;
        $totalSkipped = 0;

        foreach ($results as $specName => $result) {
            [$suite, $suiteTests, $suiteFailures, $suiteSkipped] = self::renderSpec($doc, $specName, $result);
            $root->appendChild($suite);

            $totalTests += $suiteTests;
            $totalFailures += $suiteFailures;
            $totalSkipped += $suiteSkipped;
        }

        $root->setAttribute('tests', (string) $totalTests);
        $root->setAttribute('failures', (string) $totalFailures);
        $root->setAttribute('skipped', (string) $totalSkipped);

        $xml = $doc->saveXML();
        if ($xml === false) {
            // saveXML() rarely fails for the elements we build (no entities, no
            // XSLT, no surrogate halves reach createTextNode under normal
            // tracker output), but the empty-string fallback would be a silent
            // CI dashboard regression. Surface the failure so the dispatch
            // loop's existing write-failure handling can report it.
            throw new RuntimeException('Failed to serialize JUnit XML document');
        }

        return $xml;
    }

    /**
     * @param CoverageResult $result
     *
     * @return array{0: DOMElement, 1: int, 2: int, 3: int} {suite element, tests, failures, skipped}
     */
    private static function renderSpec(DOMDocument $doc, string $specName, array $result): array
    {
        $suite = $doc->createElement('testsuite');
        $suite->setAttribute('name', $specName);
        $suite->setAttribute('time', '0');

        $classname = self::CLASSNAME_PREFIX . $specName;
        $tests = 0;
        $failures = 0;
        $skipped = 0;

        foreach ($result['endpoints'] as $endpoint) {
            foreach (self::renderEndpoint($doc, $classname, $endpoint) as $entry) {
                /** @var array{element: DOMElement, isFailure: bool, isSkipped: bool} $entry */
                $suite->appendChild($entry['element']);
                $tests++;
                if ($entry['isFailure']) {
                    $failures++;
                }
                if ($entry['isSkipped']) {
                    $skipped++;
                }
            }
        }

        $suite->setAttribute('tests', (string) $tests);
        $suite->setAttribute('failures', (string) $failures);
        $suite->setAttribute('skipped', (string) $skipped);

        return [$suite, $tests, $failures, $skipped];
    }

    /**
     * Build a list of `<testcase>` elements for a single endpoint. Always
     * returns at least one element so the per-spec count stays meaningful
     * even for endpoints with no declared responses.
     *
     * @param EndpointSummary $endpoint
     *
     * @return list<array{element: DOMElement, isFailure: bool, isSkipped: bool}>
     */
    private static function renderEndpoint(DOMDocument $doc, string $classname, array $endpoint): array
    {
        $cases = [];

        foreach ($endpoint['responses'] as $row) {
            $cases[] = self::renderResponseCase($doc, $classname, $endpoint, $row);
        }

        foreach ($endpoint['unexpectedObservations'] as $obs) {
            $cases[] = self::renderUnexpectedCase($doc, $classname, $endpoint, $obs);
        }

        if ($cases === []) {
            // Without this, an endpoint that declares no responses and saw no
            // unexpected observations would disappear from JUnit entirely —
            // CI dashboards would silently under-report the spec surface.
            $cases[] = self::renderSyntheticCase($doc, $classname, $endpoint);
        }

        return $cases;
    }

    /**
     * @param EndpointSummary $endpoint
     * @param ResponseRow $row
     *
     * @return array{element: DOMElement, isFailure: bool, isSkipped: bool}
     */
    private static function renderResponseCase(
        DOMDocument $doc,
        string $classname,
        array $endpoint,
        array $row,
    ): array {
        $name = sprintf(
            '%s [%s %s]',
            $endpoint['endpoint'],
            $row['statusKey'],
            $row['contentTypeKey'],
        );
        $tc = self::baseTestcase($doc, $classname, $name);

        $isFailure = false;
        $isSkipped = false;

        switch ($row['state']) {
            case ResponseCoverageState::Validated:
                // green — no child element
                break;
            case ResponseCoverageState::Skipped:
                $skipEl = $doc->createElement('skipped');
                $skipEl->setAttribute('message', $row['skipReason'] ?? 'skipped');
                $tc->appendChild($skipEl);
                $isSkipped = true;
                break;
            case ResponseCoverageState::Uncovered:
                $failEl = $doc->createElement('failure');
                $failEl->setAttribute('type', 'UncoveredResponse');
                $failEl->setAttribute('message', sprintf(
                    'Response %s %s on %s not exercised by any test',
                    $row['statusKey'],
                    $row['contentTypeKey'],
                    $endpoint['endpoint'],
                ));
                $tc->appendChild($failEl);
                $isFailure = true;
                break;
        }

        self::appendSystemOut($doc, $tc, $endpoint, $row['hits']);

        return ['element' => $tc, 'isFailure' => $isFailure, 'isSkipped' => $isSkipped];
    }

    /**
     * @param EndpointSummary $endpoint
     * @param array{statusKey: string, contentTypeKey: string} $obs
     *
     * @return array{element: DOMElement, isFailure: bool, isSkipped: bool}
     */
    private static function renderUnexpectedCase(
        DOMDocument $doc,
        string $classname,
        array $endpoint,
        array $obs,
    ): array {
        $name = sprintf(
            '%s [unexpected %s %s]',
            $endpoint['endpoint'],
            $obs['statusKey'],
            $obs['contentTypeKey'],
        );
        $tc = self::baseTestcase($doc, $classname, $name);

        $failEl = $doc->createElement('failure');
        $failEl->setAttribute('type', 'UnexpectedObservation');
        $failEl->setAttribute('message', sprintf(
            'Observed %s %s on %s but spec declares no such response',
            $obs['statusKey'],
            $obs['contentTypeKey'],
            $endpoint['endpoint'],
        ));
        $tc->appendChild($failEl);

        self::appendSystemOut($doc, $tc, $endpoint, hits: 0);

        return ['element' => $tc, 'isFailure' => true, 'isSkipped' => false];
    }

    /**
     * @param EndpointSummary $endpoint
     *
     * @return array{element: DOMElement, isFailure: bool, isSkipped: bool}
     */
    private static function renderSyntheticCase(DOMDocument $doc, string $classname, array $endpoint): array
    {
        $tc = self::baseTestcase($doc, $classname, $endpoint['endpoint']);

        $skipEl = $doc->createElement('skipped');
        $skipEl->setAttribute(
            'message',
            $endpoint['requestReached']
                ? 'request reached, but spec has no response definitions'
                : 'no response definitions in spec; no observations recorded',
        );
        $tc->appendChild($skipEl);

        self::appendSystemOut($doc, $tc, $endpoint, hits: 0);

        return ['element' => $tc, 'isFailure' => false, 'isSkipped' => true];
    }

    private static function baseTestcase(DOMDocument $doc, string $classname, string $name): DOMElement
    {
        $tc = $doc->createElement('testcase');
        $tc->setAttribute('classname', $classname);
        $tc->setAttribute('name', $name);
        $tc->setAttribute('time', '0');

        return $tc;
    }

    /**
     * @param EndpointSummary $endpoint
     */
    private static function appendSystemOut(DOMDocument $doc, DOMElement $tc, array $endpoint, int $hits): void
    {
        $parts = [sprintf('hits=%d', $hits)];
        if ($endpoint['operationId'] !== null) {
            $parts[] = sprintf('operationId=%s', $endpoint['operationId']);
        }

        $sysOut = $doc->createElement('system-out');
        $sysOut->appendChild($doc->createTextNode(implode(' ', $parts)));
        $tc->appendChild($sysOut);
    }
}
