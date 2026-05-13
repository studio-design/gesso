<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Validation\Strict;

use const E_USER_WARNING;

use Studio\OpenApiContractTesting\Exception\StrictRequiredDriftException;
use Studio\OpenApiContractTesting\Schema\EnumDriftAsserter;
use Studio\OpenApiContractTesting\Spec\OpenApiSpecLoader;

use function array_diff;
use function array_map;
use function array_unique;
use function array_values;
use function count;
use function implode;
use function is_array;
use function is_string;
use function sort;
use function sprintf;
use function strpos;
use function strtolower;
use function strtoupper;
use function substr;
use function trigger_error;

/**
 * Compare {@see StrictRequiredTracker} observations against each spec's
 * declared `required` arrays. Surfaces endpoints whose response bodies
 * consistently include keys the spec marks as optional — a sign that the
 * spec *under-describes* the implementation.
 *
 * The companion of {@see EnumDriftAsserter}:
 *  - EnumDriftAsserter: PHP enum cases vs spec `enum:` arrays (static).
 *  - StrictRequiredAsserter: runtime response body keys vs spec `required`
 *    arrays (per-test-run aggregate).
 *
 * `allOf` is walked when collecting `required` so composed schemas (very
 * common in modular spec layouts) do not produce false-positive reports.
 * `anyOf` / `oneOf` are intentionally NOT walked — they are disjunctions
 * and there is no safe AND-semantic for "required" across them; consult
 * the README's "Strict required limitations" section before relying on
 * those constructs.
 */
final class StrictRequiredAsserter
{
    /** Static-only utility — no instances. */
    private function __construct() {}

    /**
     * Detect any under-description drift and either throw
     * {@see StrictRequiredDriftException} (in {@see StrictRequiredMode::Fail})
     * or emit `E_USER_WARNING` (in {@see StrictRequiredMode::Warn}). Off mode
     * short-circuits to a no-op.
     *
     * @throws StrictRequiredDriftException when drift is detected and the
     *                                      mode is {@see StrictRequiredMode::Fail}
     */
    public static function assertNoDrift(StrictRequiredMode $mode): void
    {
        if ($mode === StrictRequiredMode::Off) {
            return;
        }

        $drifting = self::detectAll($mode);

        if ($drifting === []) {
            return;
        }

        $message = self::renderMessage($drifting, $mode === StrictRequiredMode::Fail);

        if ($mode === StrictRequiredMode::Fail) {
            throw new StrictRequiredDriftException($drifting, $message);
        }

        trigger_error($message, E_USER_WARNING);
    }

    /**
     * Compute reports for every recorded `(spec, endpoint, status, content-type)`
     * group that **actually drifts** — i.e. whose intersection of observed
     * keys contains at least one key not declared in the matching schema's
     * `required` array. Clean groups are filtered out because their
     * `missingFromRequired` is empty by definition; surfacing them would
     * be pure noise.
     *
     * `Off` mode returns `[]` rather than walking observations so the
     * extension can call this from coverage paths without paying the cost
     * when the feature is disabled.
     *
     * @return list<StrictRequiredReport>
     */
    public static function detectAll(StrictRequiredMode $mode): array
    {
        if ($mode === StrictRequiredMode::Off) {
            return [];
        }

        $reports = [];
        foreach (StrictRequiredTracker::recordedSpecs() as $specName) {
            foreach (self::reportsForSpec($specName) as $report) {
                if ($report->hasDrift()) {
                    $reports[] = $report;
                }
            }
        }

        return $reports;
    }

    /**
     * Render the diagnostic block describing every drifting endpoint.
     *
     * @param list<StrictRequiredReport> $reports
     */
    public static function renderMessage(array $reports, bool $isFatal): string
    {
        $severity = $isFatal ? 'FATAL' : 'WARNING';
        $count = count($reports);
        $header = sprintf(
            "[OpenAPI Strict Required] %s: %d endpoint response(s) have always-present fields missing from `required`.\n",
            $severity,
            $count,
        );

        $bodies = array_map(
            static function (StrictRequiredReport $r): string {
                $missingList = implode("\n", array_map(
                    static fn(string $k): string => '      - ' . $k,
                    $r->missingFromRequired,
                ));

                return sprintf(
                    "  %s %s  %s  %s\n    Observed in %d response(s); the following keys appeared every time but are not declared in `required`:\n%s",
                    $r->method,
                    $r->path,
                    $r->statusKey,
                    $r->contentTypeKey,
                    $r->hits,
                    $missingList,
                );
            },
            $reports,
        );

        $footer = "\nAction: add these fields to the response schema's `required` array, or set `strict_required = off` if intentional.\nConfiguration: phpunit.xml <parameter name=\"strict_required\">warn|fail|off</parameter>";

        return $header . "\n" . implode("\n\n", $bodies) . "\n" . $footer;
    }

    /**
     * @return list<StrictRequiredReport>
     */
    private static function reportsForSpec(string $specName): array
    {
        $observations = StrictRequiredTracker::getObservations($specName);
        if ($observations === []) {
            return [];
        }

        $spec = OpenApiSpecLoader::load($specName);

        $reports = [];
        foreach ($observations as $endpointKey => $responses) {
            [$method, $path] = self::splitEndpointKey($endpointKey);
            foreach ($responses as $responseKey => $row) {
                [$statusKey, $contentTypeKey] = self::splitResponseKey($responseKey);
                $required = self::collectRequiredForResponse(
                    $spec,
                    $method,
                    $path,
                    $statusKey,
                    $contentTypeKey,
                );
                if ($required === null) {
                    // Schema does not exist for this observation — nothing
                    // to compare against. Coverage-side reporting flags
                    // spec gaps separately; the strict-required asserter
                    // intentionally stays silent here.
                    continue;
                }
                $missing = array_values(array_diff($row['alwaysPresent'], $required));
                sort($missing);

                $reports[] = new StrictRequiredReport(
                    specName: $specName,
                    method: $method,
                    path: $path,
                    statusKey: $statusKey,
                    contentTypeKey: $contentTypeKey,
                    missingFromRequired: $missing,
                    hits: $row['hits'],
                );
            }
        }

        return $reports;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private static function splitEndpointKey(string $endpointKey): array
    {
        $spacePos = strpos($endpointKey, ' ');
        if ($spacePos === false) {
            return [strtoupper($endpointKey), '/'];
        }

        return [
            strtoupper(substr($endpointKey, 0, $spacePos)),
            substr($endpointKey, $spacePos + 1),
        ];
    }

    /**
     * @return array{0: string, 1: string}
     */
    private static function splitResponseKey(string $responseKey): array
    {
        $colonPos = strpos($responseKey, ':');
        if ($colonPos === false) {
            return [$responseKey, StrictRequiredTracker::ANY_CONTENT_TYPE];
        }

        return [
            substr($responseKey, 0, $colonPos),
            substr($responseKey, $colonPos + 1),
        ];
    }

    /**
     * Return the union of `required` arrays attached to the matched
     * response schema (walking `allOf`), or `null` when the response or
     * its schema cannot be located in the spec.
     *
     * @param array<string, mixed> $spec
     *
     * @return null|list<string>
     */
    private static function collectRequiredForResponse(
        array $spec,
        string $method,
        string $path,
        string $statusKey,
        string $contentTypeKey,
    ): ?array {
        $lowerMethod = strtolower($method);
        $operation = $spec['paths'][$path][$lowerMethod] ?? null;
        if (!is_array($operation)) {
            return null;
        }
        $responses = $operation['responses'] ?? null;
        if (!is_array($responses)) {
            return null;
        }
        $response = $responses[$statusKey] ?? null;
        if (!is_array($response)) {
            return null;
        }
        $content = $response['content'] ?? null;
        if (!is_array($content)) {
            return null;
        }
        $entry = $content[$contentTypeKey] ?? null;
        if (!is_array($entry)) {
            return null;
        }
        $schema = $entry['schema'] ?? null;
        if (!is_array($schema)) {
            return null;
        }

        return self::collectRequiredFromSchema($schema);
    }

    /**
     * @param array<string, mixed> $schema
     *
     * @return list<string>
     */
    private static function collectRequiredFromSchema(array $schema): array
    {
        $collected = [];
        if (isset($schema['required']) && is_array($schema['required'])) {
            foreach ($schema['required'] as $entry) {
                if (is_string($entry)) {
                    $collected[] = $entry;
                }
            }
        }
        if (isset($schema['allOf']) && is_array($schema['allOf'])) {
            foreach ($schema['allOf'] as $branch) {
                if (is_array($branch)) {
                    foreach (self::collectRequiredFromSchema($branch) as $key) {
                        $collected[] = $key;
                    }
                }
            }
        }

        return array_values(array_unique($collected));
    }
}
