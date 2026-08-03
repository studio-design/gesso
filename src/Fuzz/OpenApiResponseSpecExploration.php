<?php

declare(strict_types=1);

namespace Studio\Gesso\Fuzz;

use InvalidArgumentException;
use PHPUnit\Framework\Assert;
use Studio\Gesso\Spec\OpenApiOperationResolver;
use Studio\Gesso\Spec\OpenApiSpecLoader;
use Studio\Gesso\Validation\Response\ResponseSchemaResolution;
use Studio\Gesso\Validation\Response\ResponseSchemaResolutionOutcome;
use Studio\Gesso\Validation\Response\ResponseSchemaResolver;
use Studio\Gesso\Validation\Response\ResponseStatusTargetEnumerator;
use Studio\Gesso\Validation\Support\ContentTypeMatcher;
use Throwable;

use function array_key_exists;
use function array_keys;
use function count;
use function crc32;
use function implode;
use function is_array;
use function is_string;
use function preg_match;
use function sprintf;
use function strtoupper;

/**
 * Fluent whole-spec plan for generated SDK response round trips.
 */
final class OpenApiResponseSpecExploration
{
    use SelectsExploredOperations;

    /**
     * @var array<string, array{
     *     operationId: string,
     *     status: string,
     *     decode: callable(GeneratedResponseCase, ExploredOperation): mixed,
     *     encode: callable(mixed, GeneratedResponseCase, ExploredOperation): mixed
     * }>
     */
    private array $mappings = [];
    private bool $failOnUnmapped = false;

    public function __construct(
        private readonly string $specName,
        private readonly int $seed,
        private readonly int $extraCases,
    ) {}

    /**
     * @param callable(GeneratedResponseCase, ExploredOperation): mixed $decode
     * @param callable(mixed, GeneratedResponseCase, ExploredOperation): mixed $encode
     */
    public function mapResponse(
        string $operationId,
        int|string $status,
        callable $decode,
        callable $encode,
    ): self {
        if ($operationId === '') {
            throw new InvalidArgumentException('mapResponse() requires a non-empty operation ID.');
        }

        $normalizedStatus = self::normalizeStatusSelector($status);
        $key = self::mappingKey($operationId, $normalizedStatus);
        if (array_key_exists($key, $this->mappings)) {
            throw new InvalidArgumentException(sprintf(
                "A response mapping is already registered for operation '%s' status '%s'.",
                $operationId,
                $normalizedStatus,
            ));
        }

        $this->mappings[$key] = [
            'operationId' => $operationId,
            'status' => $normalizedStatus,
            'decode' => $decode,
            'encode' => $encode,
        ];

        return $this;
    }

    public function failOnUnmapped(bool $fail = true): self
    {
        $this->failOnUnmapped = $fail;

        return $this;
    }

    public function assertRoundTrips(): ResponseSpecExplorationSummary
    {
        $spec = OpenApiSpecLoader::load($this->specName);
        $paths = SpecPathsPreflight::validatedPaths($this->specName, $spec);
        $resolver = new ResponseSchemaResolver();
        $selected = 0;
        $executedResponses = 0;
        $executedCases = 0;
        $operations = [];
        $decodeFailures = [];
        $roundTripFailures = [];
        $skips = [];

        foreach ($paths as $path => $pathItem) {
            foreach (OpenApiOperationResolver::declaredOperations($pathItem) as $declared) {
                $operation = $this->operationFromDeclaration($path, $declared['method'], $declared['operation']);
                if (!$this->matchesFilters($operation)) {
                    continue;
                }
                $selected++;

                $operationResolution = $resolver->resolveOperation(
                    $this->specName,
                    $operation->method,
                    $operation->path,
                );
                if ($operationResolution->outcome !== ResponseSchemaResolutionOutcome::Resolved ||
                    $operationResolution->responses === null
                ) {
                    throw new InvalidArgumentException($operationResolution->message ?? sprintf(
                        'Response operation resolution stopped with outcome=%s.',
                        $operationResolution->outcome->name,
                    ));
                }

                $operationExecuted = false;
                foreach (ResponseStatusTargetEnumerator::enumerate($operationResolution->responses) as $target) {
                    if ($target['wireStatus'] === null) {
                        $skips[] = new ResponseSpecExplorationSkip(
                            $operation,
                            $target['selector'],
                            null,
                            'UnreachableStatus: no wire status can select this declared response.',
                        );

                        continue;
                    }

                    $initialResolution = $resolver->resolveResponseSchema(
                        $operationResolution,
                        $target['wireStatus'],
                    );
                    self::throwIfMalformed($initialResolution);

                    $contentTypes = $initialResolution->responseSpec === null
                        ? []
                        : self::jsonContentTypes($initialResolution->responseSpec);
                    if ($contentTypes === []) {
                        $skips[] = self::unsupportedSkip($operation, $target['selector'], $initialResolution);

                        continue;
                    }

                    foreach ($contentTypes as $contentType) {
                        $resolution = $resolver->resolveResponseSchema(
                            $operationResolution,
                            $target['wireStatus'],
                            $contentType === 'application/*' ? null : $contentType,
                        );
                        self::throwIfMalformed($resolution);
                        if ($resolution->outcome !== ResponseSchemaResolutionOutcome::Resolved ||
                            $resolution->contentType === null
                        ) {
                            $skips[] = self::unsupportedSkip($operation, $target['selector'], $resolution);

                            continue;
                        }

                        $mapping = $operation->operationId === null
                            ? null
                            : ($this->mappings[self::mappingKey($operation->operationId, $target['selector'])] ?? null);
                        if ($mapping === null) {
                            $skips[] = new ResponseSpecExplorationSkip(
                                $operation,
                                $target['selector'],
                                $resolution->contentType,
                                sprintf(
                                    "No SDK mapping registered for operation '%s' response '%s' (%s).",
                                    $operation->operationId ?? '(none)',
                                    $target['selector'],
                                    $resolution->contentType,
                                ),
                                mappingGap: true,
                            );

                            continue;
                        }

                        $cases = OpenApiResponseExplorer::explore(
                            $this->specName,
                            $operation->method,
                            $operation->path,
                            $target['wireStatus'],
                            $contentType === 'application/*' ? null : $contentType,
                            $operation->seed,
                            $this->extraCases,
                        );

                        foreach ($cases as $case) {
                            try {
                                $decoded = ($mapping['decode'])($case, $operation);
                            } catch (Throwable $failure) {
                                $decodeFailures[] = self::failure(
                                    $operation,
                                    $target['selector'],
                                    $target['wireStatus'],
                                    $resolution->contentType,
                                    $case,
                                    $failure,
                                );

                                continue;
                            }

                            try {
                                $encoded = ($mapping['encode'])($decoded, $case, $operation);
                                $case->assertRoundTrip($encoded);
                            } catch (Throwable $failure) {
                                $roundTripFailures[] = self::failure(
                                    $operation,
                                    $target['selector'],
                                    $target['wireStatus'],
                                    $resolution->contentType,
                                    $case,
                                    $failure,
                                );

                                continue;
                            }

                            $executedCases++;
                        }

                        $executedResponses++;
                        $operationExecuted = true;
                    }
                }

                if ($operationExecuted) {
                    $operations[] = $operation;
                }
            }
        }

        if ($selected === 0) {
            throw new InvalidArgumentException(sprintf(
                "Spec-wide SDK round-trip filters matched no operations in spec '%s'.",
                $this->specName,
            ));
        }

        $summary = new ResponseSpecExplorationSummary(
            executedOperations: count($operations),
            executedResponses: $executedResponses,
            executedCases: $executedCases,
            operations: $operations,
            decodeFailures: $decodeFailures,
            roundTripFailures: $roundTripFailures,
            skips: $skips,
        );

        if ($summary->hasFailures() || ($this->failOnUnmapped && $summary->hasMappingGaps())) {
            Assert::fail(self::failureMessage($summary, $this->failOnUnmapped));
        }

        return $summary;
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

    private static function normalizeStatusSelector(int|string $status): string
    {
        $normalized = (string) $status;
        if (preg_match('/^[1-5][0-9]{2}$/', $normalized) === 1 || $normalized === 'default') {
            return $normalized;
        }
        if (preg_match('/^[1-5](?:XX|xx)$/', $normalized) === 1) {
            return strtoupper($normalized);
        }

        throw new InvalidArgumentException(sprintf(
            "mapResponse() status must be an exact status, range status, or default; got '%s'.",
            $normalized,
        ));
    }

    private static function mappingKey(string $operationId, string $status): string
    {
        return $operationId . "\0" . $status;
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

    private static function unsupportedSkip(
        ExploredOperation $operation,
        string $status,
        ResponseSchemaResolution $resolution,
    ): ResponseSpecExplorationSkip {
        $detail = $resolution->message ?? $resolution->skipReason ?? match ($resolution->outcome) {
            ResponseSchemaResolutionOutcome::NoContent => 'the response declares no content block',
            ResponseSchemaResolutionOutcome::NoJsonContent => 'the response declares no JSON-compatible content type',
            ResponseSchemaResolutionOutcome::MissingSchema => 'the selected response media type declares no schema',
            default => 'the response schema could not be resolved',
        };

        return new ResponseSpecExplorationSkip(
            $operation,
            $status,
            $resolution->contentType,
            $resolution->outcome->name . ': ' . $detail,
        );
    }

    private static function failure(
        ExploredOperation $operation,
        string $status,
        int $wireStatus,
        string $contentType,
        GeneratedResponseCase $case,
        Throwable $failure,
    ): ResponseSpecExplorationFailure {
        return new ResponseSpecExplorationFailure(
            $operation,
            $status,
            $wireStatus,
            $contentType,
            $case->caseIndex,
            $case->seed,
            $case->pinnedBranch,
            $case->replaySnippet(),
            $failure->getMessage(),
            $failure,
        );
    }

    private static function failureMessage(ResponseSpecExplorationSummary $summary, bool $includeMappingGaps): string
    {
        $sections = [];

        if ($summary->decodeFailures !== []) {
            $lines = ['Decode failures:'];
            foreach ($summary->decodeFailures as $failure) {
                $lines[] = self::failureLine($failure);
            }
            $sections[] = implode("\n", $lines);
        }

        if ($summary->roundTripFailures !== []) {
            $lines = ['Round-trip failures:'];
            foreach ($summary->roundTripFailures as $failure) {
                $lines[] = self::failureLine($failure);
            }
            $sections[] = implode("\n", $lines);
        }

        if ($includeMappingGaps && $summary->hasMappingGaps()) {
            $lines = ['Unmapped response schemas:'];
            foreach ($summary->skips as $skip) {
                if (!$skip->mappingGap) {
                    continue;
                }

                $lines[] = sprintf(
                    '- %s %s operation=%s status=%s content-type=%s',
                    $skip->operation->method,
                    $skip->operation->path,
                    $skip->operation->operationId ?? '(none)',
                    $skip->status,
                    $skip->contentType ?? '(none)',
                );
            }
            $sections[] = implode("\n", $lines);
        }

        return implode("\n\n", $sections);
    }

    private static function failureLine(ResponseSpecExplorationFailure $failure): string
    {
        return sprintf(
            '- %s %s operation=%s status=%s wire-status=%d content-type=%s seed=%d case=%d pinned=%s: %s; replay: %s',
            $failure->operation->method,
            $failure->operation->path,
            $failure->operation->operationId ?? '(none)',
            $failure->status,
            $failure->wireStatus,
            $failure->contentType,
            $failure->seed,
            $failure->caseIndex,
            $failure->pinnedBranch ?? 'none',
            $failure->message,
            $failure->replay,
        );
    }

    private function operationFromDeclaration(string $path, string $method, mixed $rawOperation): ExploredOperation
    {
        $normalizedMethod = OpenApiOperationResolver::normalizeMethodForKey($method);
        $derivedSeed = crc32(implode("\0", [$this->specName, $normalizedMethod, $path, (string) $this->seed])) & 0x7fffffff;

        return ExploredOperation::fromDeclaration($this->specName, $path, $method, $rawOperation, $derivedSeed);
    }
}
