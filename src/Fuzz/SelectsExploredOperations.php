<?php

declare(strict_types=1);

namespace Studio\Gesso\Fuzz;

use Studio\Gesso\Spec\OpenApiOperationResolver;

use function array_intersect;
use function array_map;
use function crc32;
use function implode;
use function in_array;

/**
 * Fluent include/exclude operation selection shared by the whole-spec
 * exploration plan and the contract-check plan. Extracted so the two builders
 * cannot drift apart on filter semantics.
 *
 * @internal Not part of the package's public API. Do not use from user code.
 */
trait SelectsExploredOperations
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
        $this->includedMethods = array_map(OpenApiOperationResolver::normalizeMethodForKey(...), $methods);

        return $this;
    }

    /** @param list<string> $methods */
    public function excludeMethods(array $methods): self
    {
        $this->excludedMethods = array_map(OpenApiOperationResolver::normalizeMethodForKey(...), $methods);

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

    /** Build the operation record with a per-operation seed derived from the plan's global seed. */
    private function operationFromDeclaration(string $path, string $method, mixed $rawOperation): ExploredOperation
    {
        $normalizedMethod = OpenApiOperationResolver::normalizeMethodForKey($method);
        $derivedSeed = crc32(implode("\0", [$this->specName, $normalizedMethod, $path, (string) $this->seed])) & 0x7fffffff;

        return ExploredOperation::fromDeclaration($this->specName, $path, $method, $rawOperation, $derivedSeed);
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
}
