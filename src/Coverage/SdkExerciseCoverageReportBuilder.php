<?php

declare(strict_types=1);

namespace Studio\Gesso\Coverage;

use InvalidArgumentException;
use Studio\Gesso\Fuzz\SpecPathsPreflight;
use Studio\Gesso\Spec\OpenApiOperationResolver;
use Studio\Gesso\Spec\OpenApiSpecLoader;
use Studio\Gesso\Validation\Response\ResponseSchemaResolution;
use Studio\Gesso\Validation\Response\ResponseSchemaResolutionOutcome;
use Studio\Gesso\Validation\Response\ResponseSchemaResolver;
use Studio\Gesso\Validation\Response\ResponseStatusTargetEnumerator;
use Studio\Gesso\Validation\Support\ContentTypeMatcher;

use function array_keys;
use function count;
use function is_array;
use function is_string;
use function sprintf;
use function strcmp;
use function usort;

/**
 * Reconciles SDK decoder observations with eligible response schemas in the
 * current resolved OpenAPI document.
 *
 * @internal Output is consumed by coverage renderers and threshold gates.
 *
 * @phpstan-type SdkExerciseResponseRow array{
 *   endpoint: string,
 *   method: string,
 *   path: string,
 *   operationId: ?string,
 *   statusKey: string,
 *   contentTypeKey: string,
 *   exercised: bool,
 *   hits: int
 * }
 * @phpstan-type SdkExerciseUnexpected array{
 *   endpoint: string,
 *   statusKey: string,
 *   contentTypeKey: string,
 *   hits: int
 * }
 * @phpstan-type SdkExerciseCoverageResult array{
 *   responses: list<SdkExerciseResponseRow>,
 *   responseTotal: int,
 *   responseExercised: int,
 *   responseUnexercised: int,
 *   unexpectedObservations: list<SdkExerciseUnexpected>
 * }
 */
final class SdkExerciseCoverageReportBuilder
{
    private function __construct() {}

    /** @return SdkExerciseCoverageResult */
    public static function build(string $specName, SdkExerciseCoverageTracker $tracker): array
    {
        $spec = OpenApiSpecLoader::load($specName);
        $paths = SpecPathsPreflight::validatedPaths($specName, $spec);
        $resolver = new ResponseSchemaResolver();
        $remaining = $tracker->observationsForSpecOn($specName);
        $responses = [];

        foreach ($paths as $path => $pathItem) {
            foreach (OpenApiOperationResolver::declaredOperations($pathItem) as $declared) {
                $method = OpenApiOperationResolver::normalizeMethodForKey($declared['method']);
                $operation = $declared['operation'];
                if (!is_array($operation)) {
                    throw new InvalidArgumentException(sprintf(
                        "Malformed operation for %s %s in '%s' spec: expected object.",
                        $method,
                        $path,
                        $specName,
                    ));
                }
                $operationId = is_string($operation['operationId'] ?? null)
                    ? $operation['operationId']
                    : null;

                $operationResolution = $resolver->resolveOperation($specName, $method, $path);
                if ($operationResolution->outcome !== ResponseSchemaResolutionOutcome::Resolved ||
                    $operationResolution->responses === null
                ) {
                    throw new InvalidArgumentException($operationResolution->message ?? sprintf(
                        'Response operation resolution stopped with outcome=%s.',
                        $operationResolution->outcome->name,
                    ));
                }

                foreach (ResponseStatusTargetEnumerator::enumerate($operationResolution->responses) as $target) {
                    if ($target['wireStatus'] === null) {
                        continue;
                    }

                    $initialResolution = $resolver->resolveResponseSchema(
                        $operationResolution,
                        $target['wireStatus'],
                    );
                    self::throwIfMalformed($initialResolution);
                    if ($initialResolution->responseSpec === null) {
                        continue;
                    }

                    foreach (self::jsonContentTypes($initialResolution->responseSpec) as $contentType) {
                        $resolution = $resolver->resolveResponseSchema(
                            $operationResolution,
                            $target['wireStatus'],
                            ContentTypeMatcher::normalizeMediaType($contentType) === 'application/*'
                                ? null
                                : $contentType,
                        );
                        self::throwIfMalformed($resolution);
                        if ($resolution->outcome !== ResponseSchemaResolutionOutcome::Resolved ||
                            $resolution->statusKey === null ||
                            $resolution->contentType === null
                        ) {
                            continue;
                        }

                        $endpoint = $method . ' ' . $path;
                        $statusKey = $resolution->statusKey;
                        $contentTypeKey = $resolution->contentType;
                        $hits = $remaining[$endpoint][$statusKey][$contentTypeKey] ?? 0;
                        if ($hits > 0) {
                            unset($remaining[$endpoint][$statusKey][$contentTypeKey]);
                        }

                        $responses[] = [
                            'endpoint' => $endpoint,
                            'method' => $method,
                            'path' => $path,
                            'operationId' => $operationId,
                            'statusKey' => $statusKey,
                            'contentTypeKey' => $contentTypeKey,
                            'exercised' => $hits > 0,
                            'hits' => $hits,
                        ];
                    }
                }
            }
        }

        usort($responses, self::compareRows(...));
        $unexpected = self::unexpectedRows($remaining);
        $responseExercised = 0;
        foreach ($responses as $response) {
            if ($response['exercised']) {
                $responseExercised++;
            }
        }
        $responseTotal = count($responses);

        return [
            'responses' => $responses,
            'responseTotal' => $responseTotal,
            'responseExercised' => $responseExercised,
            'responseUnexercised' => $responseTotal - $responseExercised,
            'unexpectedObservations' => $unexpected,
        ];
    }

    /**
     * @param array<string, mixed> $responseSpec
     *
     * @return list<string>
     */
    private static function jsonContentTypes(array $responseSpec): array
    {
        $content = $responseSpec['content'] ?? [];
        if (!is_array($content)) {
            return [];
        }

        $contentTypes = [];
        foreach ($content as $contentType => $_mediaType) {
            if (!is_string($contentType)) {
                continue;
            }
            if (ContentTypeMatcher::isJsonContentType(ContentTypeMatcher::normalizeMediaType($contentType))) {
                $contentTypes[] = $contentType;
            }
        }

        if ($contentTypes !== []) {
            return $contentTypes;
        }

        foreach (array_keys($content) as $contentType) {
            if (is_string($contentType) && ContentTypeMatcher::normalizeMediaType($contentType) === 'application/*') {
                return [$contentType];
            }
        }

        return [];
    }

    private static function throwIfMalformed(ResponseSchemaResolution $resolution): void
    {
        if ($resolution->outcome !== ResponseSchemaResolutionOutcome::MalformedSpec &&
            $resolution->outcome !== ResponseSchemaResolutionOutcome::MalformedResponse &&
            $resolution->outcome !== ResponseSchemaResolutionOutcome::MalformedContent
        ) {
            return;
        }

        throw new InvalidArgumentException($resolution->message ?? sprintf(
            'Response schema resolution stopped with outcome=%s.',
            $resolution->outcome->name,
        ));
    }

    /**
     * @param SdkExerciseResponseRow $left
     * @param SdkExerciseResponseRow $right
     */
    private static function compareRows(array $left, array $right): int
    {
        return strcmp(
            $left['endpoint'] . "\0" . $left['statusKey'] . "\0" . $left['contentTypeKey'],
            $right['endpoint'] . "\0" . $right['statusKey'] . "\0" . $right['contentTypeKey'],
        );
    }

    /**
     * @param array<string, array<int|string, array<string, int>>> $observations
     *
     * @return list<SdkExerciseUnexpected>
     */
    private static function unexpectedRows(array $observations): array
    {
        $unexpected = [];
        foreach ($observations as $endpoint => $statuses) {
            foreach ($statuses as $statusKey => $contentTypes) {
                foreach ($contentTypes as $contentTypeKey => $hits) {
                    $unexpected[] = [
                        'endpoint' => $endpoint,
                        'statusKey' => (string) $statusKey,
                        'contentTypeKey' => $contentTypeKey,
                        'hits' => $hits,
                    ];
                }
            }
        }

        usort($unexpected, self::compareUnexpected(...));

        return $unexpected;
    }

    /**
     * @param SdkExerciseUnexpected $left
     * @param SdkExerciseUnexpected $right
     */
    private static function compareUnexpected(array $left, array $right): int
    {
        return strcmp(
            $left['endpoint'] . "\0" . $left['statusKey'] . "\0" . $left['contentTypeKey'],
            $right['endpoint'] . "\0" . $right['statusKey'] . "\0" . $right['contentTypeKey'],
        );
    }
}
