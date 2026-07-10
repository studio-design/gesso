<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Fuzz;

use InvalidArgumentException;
use RuntimeException;
use Studio\OpenApiContractTesting\HttpMethod;
use Studio\OpenApiContractTesting\Spec\OpenApiOperationResolver;
use Studio\OpenApiContractTesting\Spec\OpenApiSpecLoader;
use Studio\OpenApiContractTesting\Validation\Support\MalformedSpecNode;
use Throwable;

use function array_filter;
use function array_intersect;
use function array_key_exists;
use function array_map;
use function array_values;
use function crc32;
use function implode;
use function in_array;
use function is_array;
use function is_string;
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
    /** @var list<string> */
    private array $includedTags = [];

    /** @var list<string> */
    private array $excludedTags = [];

    /** @var list<string> */
    private array $includedMethods = [];

    /** @var list<string> */
    private array $excludedMethods = [];

    /** @var list<string> */
    private array $includedPaths = [];

    /** @var list<string> */
    private array $excludedPaths = [];

    /** @var list<string> */
    private array $includedOperationIds = [];

    /** @var list<string> */
    private array $excludedOperationIds = [];
    private bool $includeDeprecated = false;

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

    /** @param list<string> $tags */
    public function includeTags(array $tags): self
    {
        $this->includedTags = $tags;

        return $this;
    }

    /** @param list<string> $tags */
    public function excludeTags(array $tags): self
    {
        $this->excludedTags = $tags;

        return $this;
    }

    /** @param list<string> $methods */
    public function includeMethods(array $methods): self
    {
        $this->includedMethods = array_map(self::normalizeFilterMethod(...), $methods);

        return $this;
    }

    /** @param list<string> $methods */
    public function excludeMethods(array $methods): self
    {
        $this->excludedMethods = array_map(self::normalizeFilterMethod(...), $methods);

        return $this;
    }

    /** @param list<string> $paths */
    public function includePaths(array $paths): self
    {
        $this->includedPaths = $paths;

        return $this;
    }

    /** @param list<string> $paths */
    public function excludePaths(array $paths): self
    {
        $this->excludedPaths = $paths;

        return $this;
    }

    /** @param list<string> $operationIds */
    public function includeOperations(array $operationIds): self
    {
        $this->includedOperationIds = $operationIds;

        return $this;
    }

    /** @param list<string> $operationIds */
    public function excludeOperations(array $operationIds): self
    {
        $this->excludedOperationIds = $operationIds;

        return $this;
    }

    public function includeDeprecated(bool $include = true): self
    {
        $this->includeDeprecated = $include;

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
        $paths = $this->validatedPaths($spec);
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
                    $cases = OpenApiEndpointExplorer::explore(
                        $this->specName,
                        $operation->method,
                        $operation->path,
                        $this->casesPerOperation,
                        $operation->seed,
                    );
                } catch (InvalidArgumentException $e) {
                    $skips[] = new ExplorationSkip($operation, $e->getMessage());

                    continue;
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

    private static function normalizeFilterMethod(string $method): string
    {
        return OpenApiOperationResolver::normalizeMethodForKey($method);
    }

    /**
     * Validate every structural node required to enumerate the spec before
     * dispatching any request. A mixed valid/malformed document must fail
     * atomically instead of executing the valid prefix and silently omitting
     * the malformed remainder.
     *
     * @param array<string, mixed> $spec
     *
     * @return array<string, array<string, mixed>>
     */
    private function validatedPaths(array $spec): array
    {
        $paths = array_key_exists('paths', $spec) ? $spec['paths'] : [];
        if (MalformedSpecNode::isMalformed($paths)) {
            throw new InvalidArgumentException(sprintf(
                "Malformed 'paths' in '%s' spec: expected object, got %s.",
                $this->specName,
                MalformedSpecNode::describe($paths),
            ));
        }

        foreach ($paths as $path => $pathItem) {
            if (!is_string($path)) {
                throw new InvalidArgumentException(sprintf(
                    "Malformed 'paths' in '%s' spec: expected string path key.",
                    $this->specName,
                ));
            }

            if (MalformedSpecNode::isMalformed($pathItem)) {
                throw new InvalidArgumentException(sprintf(
                    "Malformed 'paths[\"%s\"]' in '%s' spec: expected object, got %s.",
                    $path,
                    $this->specName,
                    MalformedSpecNode::describe($pathItem),
                ));
            }

            if (array_key_exists('additionalOperations', $pathItem) &&
                MalformedSpecNode::isMalformed($pathItem['additionalOperations'])) {
                throw new InvalidArgumentException(sprintf(
                    "Malformed 'paths[\"%s\"].additionalOperations' in '%s' spec: expected object, got %s.",
                    $path,
                    $this->specName,
                    MalformedSpecNode::describe($pathItem['additionalOperations']),
                ));
            }

            foreach (OpenApiOperationResolver::declaredOperations($pathItem) as $declared) {
                if (!MalformedSpecNode::isMalformed($declared['operation'])) {
                    continue;
                }

                throw new InvalidArgumentException(sprintf(
                    "Malformed 'paths[\"%s\"].%s' for %s %s in '%s' spec: expected object, got %s.",
                    $path,
                    $declared['location'],
                    $declared['method'],
                    $path,
                    $this->specName,
                    MalformedSpecNode::describe($declared['operation']),
                ));
            }
        }

        return $paths;
    }

    private function operationFromDeclaration(string $path, string $method, mixed $rawOperation): ExploredOperation
    {
        $normalizedMethod = OpenApiOperationResolver::normalizeMethodForKey($method);
        $operation = is_array($rawOperation) ? $rawOperation : [];
        $operationId = is_string($operation['operationId'] ?? null) ? $operation['operationId'] : null;
        $tags = is_array($operation['tags'] ?? null)
            ? array_values(array_filter($operation['tags'], is_string(...)))
            : [];
        $derivedSeed = crc32(implode("\0", [$this->specName, $normalizedMethod, $path, (string) $this->seed])) & 0x7fffffff;

        return new ExploredOperation(
            $this->specName,
            $normalizedMethod,
            $path,
            $operationId,
            $tags,
            ($operation['deprecated'] ?? false) === true,
            $derivedSeed,
        );
    }

    private function matchesFilters(ExploredOperation $operation): bool
    {
        if (!$this->includeDeprecated && $operation->deprecated) {
            return false;
        }
        if ($this->includedTags !== [] && array_intersect($this->includedTags, $operation->tags) === []) {
            return false;
        }
        if (array_intersect($this->excludedTags, $operation->tags) !== []) {
            return false;
        }
        if ($this->includedMethods !== [] && !in_array($operation->method, $this->includedMethods, true)) {
            return false;
        }
        if (in_array($operation->method, $this->excludedMethods, true)) {
            return false;
        }
        if ($this->includedPaths !== [] && !in_array($operation->path, $this->includedPaths, true)) {
            return false;
        }
        if (in_array($operation->path, $this->excludedPaths, true)) {
            return false;
        }
        if ($this->includedOperationIds !== [] && !in_array($operation->operationId, $this->includedOperationIds, true)) {
            return false;
        }

        return !in_array($operation->operationId, $this->excludedOperationIds, true);
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
                    throw new RuntimeException($this->caseFailureMessage($operation, $caseIndex), 0, $e);
                }
            }
        } finally {
            if ($this->tearDown !== null) {
                ($this->tearDown)($operation);
            }
        }
    }

    private function caseFailureMessage(ExploredOperation $operation, int $caseIndex): string
    {
        return sprintf(
            "Whole-spec exploration failed.\nSpec: %s\nOperation: %s\nMethod/path: %s %s\nGlobal seed: %d\nOperation seed: %d\nCase: %d\nReplay: %s",
            $operation->specName,
            $operation->operationId ?? '(none)',
            $operation->method,
            $operation->path,
            $this->seed,
            $operation->seed,
            $caseIndex,
            $operation->replaySnippet($caseIndex),
        );
    }
}
