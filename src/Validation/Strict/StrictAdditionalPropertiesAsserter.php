<?php

declare(strict_types=1);

namespace Studio\Gesso\Validation\Strict;

use function array_map;
use function count;
use function implode;
use function sprintf;
use function strpos;
use function substr;
use function usort;

/**
 * Builds run-level undocumented response-property reports.
 *
 * @internal Used by PHPUnit and the merge CLI.
 */
final class StrictAdditionalPropertiesAsserter
{
    private function __construct() {}

    /**
     * @return list<StrictAdditionalPropertiesReport>
     */
    public static function detectAll(StrictAdditionalPropertiesTracker $tracker): array
    {
        $reports = [];
        foreach ($tracker->recordedSpecsOn() as $specName) {
            foreach ($tracker->getObservationsOn($specName) as $endpoint => $responses) {
                $space = strpos($endpoint, ' ');
                $method = substr($endpoint, 0, $space);
                $path = substr($endpoint, $space + 1);
                foreach ($responses as $response => $pointers) {
                    $colon = strpos($response, ':');
                    $statusKey = substr($response, 0, $colon);
                    $contentTypeKey = substr($response, $colon + 1);
                    foreach ($pointers as $pointer => $row) {
                        $reports[] = new StrictAdditionalPropertiesReport(
                            $specName,
                            $method,
                            $path,
                            $statusKey,
                            $contentTypeKey,
                            $pointer,
                            $row['property'],
                            $row['hits'],
                        );
                    }
                }
            }
        }
        usort($reports, static fn(StrictAdditionalPropertiesReport $a, StrictAdditionalPropertiesReport $b): int => [
            $a->specName,
            $a->method,
            $a->path,
            $a->statusKey,
            $a->contentTypeKey,
            $a->instancePointer,
        ] <=> [
            $b->specName,
            $b->method,
            $b->path,
            $b->statusKey,
            $b->contentTypeKey,
            $b->instancePointer,
        ]);

        return $reports;
    }

    /**
     * @param list<StrictAdditionalPropertiesReport> $reports
     */
    public static function renderMessage(array $reports, bool $isFatal): string
    {
        $severity = $isFatal ? 'FATAL' : 'WARNING';
        $header = sprintf(
            '[OpenAPI Strict Additional Properties] %s: %d undocumented response propert%s observed.',
            $severity,
            count($reports),
            count($reports) === 1 ? 'y was' : 'ies were',
        );
        $rows = array_map(
            static fn(StrictAdditionalPropertiesReport $report): string => sprintf(
                "  - %s (%s) — spec '%s', %s %s, %s, %s; observed in %d response(s)",
                $report->instancePointer,
                $report->propertyName,
                $report->specName,
                $report->method,
                $report->path,
                $report->statusKey,
                $report->contentTypeKey,
                $report->hits,
            ),
            $reports,
        );
        $footer = 'Action: declare each property in `properties` / `patternProperties`, explicitly document the object as open with '
            . '`additionalProperties` / `unevaluatedProperties`, or set `strict_additional_properties = off`.';

        return $header . "\n\n" . implode("\n", $rows) . "\n\n" . $footer;
    }
}
