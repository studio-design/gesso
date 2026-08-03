<?php

declare(strict_types=1);

namespace Studio\Gesso\Fuzz;

use InvalidArgumentException;
use Studio\Gesso\Spec\OpenApiOperationResolver;
use Studio\Gesso\Spec\OpenApiSpecLoader;
use Studio\Gesso\Validation\Response\ResponseSchemaResolutionOutcome;
use Studio\Gesso\Validation\Response\ResponseSchemaResolver;
use Studio\Gesso\Validation\Support\ContentTypeMatcher;

use function array_key_exists;
use function crc32;
use function implode;
use function is_string;
use function sprintf;

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
     *     wireStatus: int,
     *     decode: callable(GeneratedResponseCase, ExploredOperation): mixed,
     *     encode: callable(mixed, GeneratedResponseCase, ExploredOperation): mixed
     * }>
     */
    private array $mappings = [];

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

        $normalizedStatus = self::normalizeExactStatus($status);
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
            'wireStatus' => (int) $normalizedStatus,
            'decode' => $decode,
            'encode' => $encode,
        ];

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

        foreach ($paths as $path => $pathItem) {
            foreach (OpenApiOperationResolver::declaredOperations($pathItem) as $declared) {
                $operation = $this->operationFromDeclaration($path, $declared['method'], $declared['operation']);
                if (!$this->matchesFilters($operation)) {
                    continue;
                }
                $selected++;

                if ($operation->operationId === null) {
                    continue;
                }

                $operationExecuted = false;
                foreach ($this->mappings as $mapping) {
                    if ($mapping['operationId'] !== $operation->operationId) {
                        continue;
                    }

                    $resolution = $resolver->resolve(
                        $this->specName,
                        $operation->method,
                        $operation->path,
                        $mapping['wireStatus'],
                    );
                    if ($resolution->outcome !== ResponseSchemaResolutionOutcome::Resolved ||
                        $resolution->responseSpec === null
                    ) {
                        throw new InvalidArgumentException($resolution->message ?? $resolution->skipReason ?? sprintf(
                            'Response schema resolution stopped with outcome=%s.',
                            $resolution->outcome->name,
                        ));
                    }

                    foreach (self::jsonContentTypes($resolution->responseSpec) as $contentType) {
                        $cases = OpenApiResponseExplorer::explore(
                            $this->specName,
                            $operation->method,
                            $operation->path,
                            $mapping['wireStatus'],
                            $contentType,
                            $operation->seed,
                            $this->extraCases,
                        );

                        foreach ($cases as $case) {
                            $decoded = ($mapping['decode'])($case, $operation);
                            $encoded = ($mapping['encode'])($decoded, $case, $operation);
                            $case->assertRoundTrip($encoded);
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

        return new ResponseSpecExplorationSummary(
            executedOperations: count($operations),
            executedResponses: $executedResponses,
            executedCases: $executedCases,
            operations: $operations,
            decodeFailures: [],
            roundTripFailures: [],
            skips: [],
        );
    }

    private function operationFromDeclaration(string $path, string $method, mixed $rawOperation): ExploredOperation
    {
        $normalizedMethod = OpenApiOperationResolver::normalizeMethodForKey($method);
        $derivedSeed = crc32(implode("\0", [$this->specName, $normalizedMethod, $path, (string) $this->seed])) & 0x7fffffff;

        return ExploredOperation::fromDeclaration($this->specName, $path, $method, $rawOperation, $derivedSeed);
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

        return $contentTypes;
    }

    private static function normalizeExactStatus(int|string $status): string
    {
        $normalized = (string) $status;
        if (preg_match('/^[1-5][0-9]{2}$/', $normalized) !== 1) {
            throw new InvalidArgumentException(sprintf(
                "mapResponse() status must be an exact HTTP status between 100 and 599, got '%s'.",
                $normalized,
            ));
        }

        return $normalized;
    }

    private static function mappingKey(string $operationId, string $status): string
    {
        return $operationId . "\0" . $status;
    }
}
