<?php

declare(strict_types=1);

namespace Studio\Gesso\Fuzz;

use InvalidArgumentException;
use RuntimeException;
use Studio\Gesso\HttpMethod;
use Studio\Gesso\Spec\OpenApiOperationResolver;
use Studio\Gesso\Spec\OpenApiSpecLoader;
use Throwable;

use function crc32;
use function implode;
use function sprintf;

/**
 * Fluent, process-local whole-spec exploration plan.
 *
 * The plan stores no static aggregation state, so separate PHPUnit workers can
 * execute it independently while the existing coverage sidecars remain the
 * source of cross-process aggregation.
 */
final class OpenApiSpecExploration
{
    use SelectsExploredOperations;

    /** @var null|list<int> */
    private ?array $negativeExpectedStatusClasses = null;

    /** @var null|callable(ExploredOperation): void */
    private $authenticate;

    /** @var null|callable(ExploredOperation): void */
    private $setUp;

    /** @var null|callable(ExploredOperation): void */
    private $tearDown;

    /** @var null|callable(ExploredCase, ExploredOperation): mixed */
    private $mutateCase;

    /** @var null|callable(ExploredCase, ExploredOperation): mixed */
    private $dispatch;

    /** @var null|callable(mixed, ExploredCase, ExploredOperation): void */
    private $assertResponse;

    public function __construct(
        private readonly string $specName,
        private readonly int $casesPerOperation,
        private readonly int $seed,
    ) {}

    /**
     * Switch the plan to single-constraint invalid cases. The expected status
     * classes are carried by every ExploredCase for the response assertion.
     *
     * @param list<int> $expectedStatusClasses
     */
    public function negativeCases(array $expectedStatusClasses): self
    {
        if ($expectedStatusClasses === []) {
            throw new InvalidArgumentException('negativeCases() requires at least one expected HTTP status class.');
        }
        foreach ($expectedStatusClasses as $statusClass) {
            if ($statusClass < 1 || $statusClass > 5) {
                throw new InvalidArgumentException(sprintf(
                    'Expected HTTP status class must be between 1 and 5, got %d.',
                    $statusClass,
                ));
            }
        }
        $this->negativeExpectedStatusClasses = $expectedStatusClasses;

        return $this;
    }

    /** @param callable(ExploredOperation): void $callback */
    public function authenticateUsing(callable $callback): self
    {
        $this->authenticate = $callback;

        return $this;
    }

    /** @param callable(ExploredOperation): void $callback */
    public function setUpUsing(callable $callback): self
    {
        $this->setUp = $callback;

        return $this;
    }

    /** @param callable(ExploredOperation): void $callback */
    public function tearDownUsing(callable $callback): self
    {
        $this->tearDown = $callback;

        return $this;
    }

    /** @param callable(ExploredCase, ExploredOperation): ExploredCase $callback */
    public function mutateCasesUsing(callable $callback): self
    {
        $this->mutateCase = $callback;

        return $this;
    }

    /** @param callable(ExploredCase, ExploredOperation): mixed $callback */
    public function dispatchUsing(callable $callback): self
    {
        $this->dispatch = $callback;

        return $this;
    }

    /** @param callable(mixed, ExploredCase, ExploredOperation): void $callback */
    public function assertResponseUsing(callable $callback): self
    {
        $this->assertResponse = $callback;

        return $this;
    }

    public function assertResponses(): SpecExplorationSummary
    {
        if ($this->dispatch === null) {
            throw new InvalidArgumentException('Whole-spec exploration requires dispatchUsing() before assertResponses().');
        }

        $spec = OpenApiSpecLoader::load($this->specName);
        $paths = SpecPathsPreflight::validatedPaths($this->specName, $spec);
        $selected = 0;
        $executedOperations = 0;
        $executedCases = 0;
        $operations = [];
        $skips = [];

        foreach ($paths as $path => $pathItem) {
            foreach (OpenApiOperationResolver::declaredOperations($pathItem) as $declared) {
                $operation = $this->operationFromDeclaration($path, $declared['method'], $declared['operation']);
                if (!$this->matchesFilters($operation)) {
                    continue;
                }
                $selected++;

                if (HttpMethod::tryFrom($operation->method) === null) {
                    $skips[] = new ExplorationSkip(
                        $operation,
                        sprintf('HTTP method is not supported by the explorer. Supported: %s.', HttpMethod::listOfValues()),
                    );

                    continue;
                }

                try {
                    $cases = $this->negativeExpectedStatusClasses === null
                        ? OpenApiEndpointExplorer::explore(
                            $this->specName,
                            $operation->method,
                            $operation->path,
                            $this->casesPerOperation,
                            $operation->seed,
                        )
                        : OpenApiEndpointExplorer::exploreInvalid(
                            $this->specName,
                            $operation->method,
                            $operation->path,
                            $this->negativeExpectedStatusClasses,
                            $this->casesPerOperation,
                            $operation->seed,
                        );
                } catch (InvalidArgumentException $e) {
                    $skips[] = new ExplorationSkip($operation, $e->getMessage());

                    continue;
                } catch (Throwable $e) {
                    throw new RuntimeException($this->generationFailureMessage($operation, $e), 0, $e);
                }

                $this->runOperation($operation, $cases, $executedCases);
                $executedOperations++;
                $operations[] = $operation;
            }
        }

        if ($selected === 0) {
            throw new InvalidArgumentException(sprintf(
                "Whole-spec exploration filters matched no operations in spec '%s'.",
                $this->specName,
            ));
        }

        return new SpecExplorationSummary($executedOperations, $executedCases, $operations, $skips);
    }

    private function operationFromDeclaration(string $path, string $method, mixed $rawOperation): ExploredOperation
    {
        $normalizedMethod = OpenApiOperationResolver::normalizeMethodForKey($method);
        $derivedSeed = crc32(implode("\0", [$this->specName, $normalizedMethod, $path, (string) $this->seed])) & 0x7fffffff;

        return ExploredOperation::fromDeclaration($this->specName, $path, $method, $rawOperation, $derivedSeed);
    }

    private function runOperation(
        ExploredOperation $operation,
        ExplorationCases $cases,
        int &$executedCases,
    ): void {
        try {
            if ($this->setUp !== null) {
                ($this->setUp)($operation);
            }
            if ($this->authenticate !== null) {
                ($this->authenticate)($operation);
            }

            foreach ($cases as $caseIndex => $generatedCase) {
                $case = $generatedCase;

                try {
                    if ($this->mutateCase !== null) {
                        $mutatedCase = ($this->mutateCase)($case, $operation);
                        if (!$mutatedCase instanceof ExploredCase) {
                            throw new InvalidArgumentException('mutateCasesUsing() must return an ExploredCase.');
                        }
                        $case = $mutatedCase;
                    }

                    $response = ($this->dispatch)($case, $operation);
                    if ($this->assertResponse !== null) {
                        ($this->assertResponse)($response, $case, $operation);
                    }
                    $executedCases++;
                } catch (Throwable $e) {
                    throw new RuntimeException($this->caseFailureMessage($operation, $case, $caseIndex), 0, $e);
                }
            }
        } finally {
            if ($this->tearDown !== null) {
                ($this->tearDown)($operation);
            }
        }
    }

    private function caseFailureMessage(ExploredOperation $operation, ExploredCase $case, int $caseIndex): string
    {
        return sprintf(
            "Whole-spec exploration failed.\nSpec: %s\nOperation: %s\nMethod/path: %s %s\nGlobal seed: %d\nOperation seed: %d\nCase: %d\nReplay: %s\nCurl: %s",
            $operation->specName,
            $operation->operationId ?? '(none)',
            $operation->method,
            $operation->path,
            $this->seed,
            $operation->seed,
            $caseIndex,
            $case->replaySnippet($operation->specName),
            $case->curlSnippet(),
        );
    }

    private function generationFailureMessage(ExploredOperation $operation, Throwable $failure): string
    {
        $caseIndex = $failure instanceof FuzzGenerationException ? $failure->caseIndex : null;
        $case = $caseIndex === null ? '(unknown)' : (string) $caseIndex;
        $replay = $caseIndex === null
            ? '(unavailable before a case index was assigned)'
            : $this->generationReplaySnippet($operation, $caseIndex);

        return sprintf(
            "Whole-spec input generation failed.\nSpec: %s\nOperation: %s\nMethod/path: %s %s\nGlobal seed: %d\nOperation seed: %d\nCase: %s\nReplay: %s",
            $operation->specName,
            $operation->operationId ?? '(none)',
            $operation->method,
            $operation->path,
            $this->seed,
            $operation->seed,
            $case,
            $replay,
        );
    }

    private function generationReplaySnippet(ExploredOperation $operation, int $caseIndex): string
    {
        if ($this->negativeExpectedStatusClasses !== null) {
            return $operation->replayInvalidSnippet($caseIndex, $this->negativeExpectedStatusClasses);
        }

        return $operation->replaySnippet($caseIndex);
    }
}
