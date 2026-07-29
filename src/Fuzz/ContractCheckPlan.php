<?php

declare(strict_types=1);

namespace Studio\Gesso\Fuzz;

use InvalidArgumentException;
use RuntimeException;
use Studio\Gesso\HttpMethod;
use Studio\Gesso\Spec\OpenApiOperationResolver;
use Studio\Gesso\Spec\OpenApiSpecLoader;
use Throwable;

use function array_key_exists;
use function array_values;
use function count;
use function crc32;
use function implode;
use function in_array;
use function sprintf;
use function strtoupper;

/**
 * Fluent, process-local plan that dispatches named negative contract checks
 * ({@see ContractCheck}) and collects every result into one
 * {@see ContractCheckSummary} instead of failing on the first probe.
 */
final class ContractCheckPlan
{
    use SelectsExploredOperations;

    /** @var list<ContractCheck> */
    private array $checks = [];

    /** @var array<string, list<int>> keyed by ContractCheck value */
    private array $expectedStatusOverrides = [];

    /** @var null|callable(ExploredOperation): void */
    private $authenticate;

    /** @var null|callable(ExploredOperation): void */
    private $setUp;

    /** @var null|callable(ExploredOperation): void */
    private $tearDown;

    /** @var null|callable(ExploredCase): mixed */
    private $dispatch;

    public function __construct(
        private readonly string $specName,
        private readonly int $seed,
    ) {}

    /** @param list<ContractCheck> $checks */
    public function checks(array $checks): self
    {
        if ($checks === []) {
            throw new InvalidArgumentException('checks() requires at least one ContractCheck.');
        }

        $deduplicated = [];
        foreach ($checks as $check) {
            $deduplicated[$check->value] = $check;
        }
        $this->checks = array_values($deduplicated);

        return $this;
    }

    /**
     * Replace the default pass statuses for one check
     * ({@see ContractCheck::defaultExpectedStatuses()}) with an exact list.
     *
     * @param list<int> $statuses
     */
    public function expectedStatuses(ContractCheck $check, array $statuses): self
    {
        if ($statuses === []) {
            throw new InvalidArgumentException('expectedStatuses() requires at least one HTTP status.');
        }
        foreach ($statuses as $status) {
            if ($status < 100 || $status > 599) {
                throw new InvalidArgumentException(sprintf('Expected HTTP status must be between 100 and 599, got %d.', $status));
            }
        }
        $this->expectedStatusOverrides[$check->value] = $statuses;

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

    /** @param callable(ExploredCase): mixed $callback */
    public function dispatchUsing(callable $callback): self
    {
        $this->dispatch = $callback;

        return $this;
    }

    public function report(): ContractCheckSummary
    {
        if ($this->dispatch === null) {
            throw new InvalidArgumentException('The contract check plan requires dispatchUsing() before report().');
        }
        if ($this->checks === []) {
            throw new InvalidArgumentException('The contract check plan requires checks() with at least one check before report().');
        }

        $spec = OpenApiSpecLoader::load($this->specName);
        $paths = SpecPathsPreflight::validatedPaths($this->specName, $spec);

        $failures = [];
        $skips = [];
        /** @var array<string, true> */
        $probedPathSet = [];
        $dispatchedProbes = 0;
        $selected = 0;

        foreach ($paths as $path => $pathItem) {
            foreach ($this->checks as $check) {
                $derivedSeed = $this->derivedSeed($check, $path);
                $matching = [];
                foreach (OpenApiOperationResolver::declaredOperations($pathItem) as $declared) {
                    $operation = ExploredOperation::fromDeclaration(
                        $this->specName,
                        $path,
                        $declared['method'],
                        $declared['operation'],
                        $derivedSeed,
                    );
                    if ($this->matchesFilters($operation)) {
                        $matching[] = $operation;
                    }
                }
                if ($matching === []) {
                    continue;
                }
                $selected += count($matching);

                $probe = match ($check) {
                    ContractCheck::UnsupportedMethod => $this->buildUnsupportedMethodProbe($check, $path, $pathItem, $matching, $derivedSeed, $skips),
                };
                if ($probe === null) {
                    continue;
                }
                [$case, $sourceOperation] = $probe;
                $probedPathSet[$path] = true;

                $actualStatus = $this->dispatchProbe($check, $case, $sourceOperation, $derivedSeed);
                $dispatchedProbes++;

                $expected = $this->expectedStatusOverrides[$check->value] ?? $check->defaultExpectedStatuses();
                if (!in_array($actualStatus, $expected, true)) {
                    $failures[] = new ContractCheckFailure(
                        $check,
                        $case->method->value,
                        $path,
                        null,
                        $expected,
                        $actualStatus,
                        $case,
                    );
                }
            }
        }

        if ($selected === 0) {
            throw new InvalidArgumentException(sprintf(
                "Contract checks matched no operations in spec '%s'.",
                $this->specName,
            ));
        }

        return new ContractCheckSummary(count($probedPathSet), $dispatchedProbes, $failures, $skips);
    }

    private function derivedSeed(ContractCheck $check, string $path): int
    {
        return crc32(implode("\0", [$this->specName, $check->value, $path, (string) $this->seed])) & 0x7fffffff;
    }

    /**
     * Choose one undocumented explorable method for the path and build the
     * probe case from a generatable documented operation's concrete values.
     * `additionalOperations` names are never probe candidates: they are
     * case-sensitive custom methods outside the fixed HTTP set.
     *
     * @param array<string, mixed> $pathItem
     * @param non-empty-list<ExploredOperation> $matching
     * @param list<ContractCheckSkip> $skips
     *
     * @return null|array{ExploredCase, ExploredOperation}
     */
    private function buildUnsupportedMethodProbe(
        ContractCheck $check,
        string $path,
        array $pathItem,
        array $matching,
        int $derivedSeed,
        array &$skips,
    ): ?array {
        $documented = [];
        foreach (OpenApiOperationResolver::FIXED_OPERATION_FIELDS as $field) {
            if (array_key_exists($field, $pathItem)) {
                $documented[] = strtoupper($field);
            }
        }

        $candidates = [];
        foreach (HttpMethod::cases() as $method) {
            if (!in_array($method->value, $documented, true)) {
                $candidates[] = $method;
            }
        }
        if ($candidates === []) {
            $skips[] = new ContractCheckSkip(
                $check,
                $path,
                null,
                'Every explorable HTTP method is documented for this path; no undocumented method to probe.',
            );

            return null;
        }

        $probeMethod = $candidates[$derivedSeed % count($candidates)];

        $lastReason = null;
        foreach ($matching as $operation) {
            if (HttpMethod::tryFrom($operation->method) === null) {
                $lastReason ??= sprintf(
                    'No documented operation with an explorer-supported method (%s) is available to generate concrete request values.',
                    HttpMethod::listOfValues(),
                );

                continue;
            }

            try {
                $cases = OpenApiEndpointExplorer::explore($this->specName, $operation->method, $operation->path, 1, $derivedSeed);
            } catch (InvalidArgumentException $e) {
                $lastReason = $e->getMessage();

                continue;
            } catch (Throwable $e) {
                throw new RuntimeException($this->generationFailureMessage($check, $operation, $derivedSeed, $e), 0, $e);
            }

            foreach ($cases as $sourceCase) {
                return [
                    new ExploredCase(
                        null,
                        [],
                        [],
                        $sourceCase->pathParams,
                        $probeMethod,
                        $path,
                        seed: $derivedSeed,
                        caseIndex: 0,
                    ),
                    $operation,
                ];
            }
        }

        $skips[] = new ContractCheckSkip($check, $path, null, $lastReason ?? 'No documented operation could generate concrete request values.');

        return null;
    }

    private function generationFailureMessage(
        ContractCheck $check,
        ExploredOperation $operation,
        int $derivedSeed,
        Throwable $failure,
    ): string {
        return sprintf(
            "Contract check input generation failed.\nCheck: %s\nSpec: %s\nOperation: %s\nMethod/path: %s %s\nGlobal seed: %d\nDerived seed: %d\nMessage: %s",
            $check->value,
            $this->specName,
            $operation->operationId ?? '(none)',
            $operation->method,
            $operation->path,
            $this->seed,
            $derivedSeed,
            $failure->getMessage(),
        );
    }

    private function dispatchProbe(ContractCheck $check, ExploredCase $case, ExploredOperation $operation, int $derivedSeed): int
    {
        try {
            if ($this->setUp !== null) {
                ($this->setUp)($operation);
            }
            if ($this->authenticate !== null) {
                ($this->authenticate)($operation);
            }

            try {
                $response = ($this->dispatch)($case);

                return ResponseStatusExtractor::extract($response);
            } catch (Throwable $e) {
                throw new RuntimeException(sprintf(
                    "Contract check dispatch failed.\nCheck: %s\nSpec: %s\nMethod/path: %s %s\nGlobal seed: %d\nDerived seed: %d\nCurl: %s",
                    $check->value,
                    $this->specName,
                    $case->method->value,
                    $case->matchedPath,
                    $this->seed,
                    $derivedSeed,
                    $case->curlSnippet(),
                ), 0, $e);
            }
        } finally {
            if ($this->tearDown !== null) {
                ($this->tearDown)($operation);
            }
        }
    }
}
