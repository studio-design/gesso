<?php

declare(strict_types=1);

namespace Studio\Gesso\Fuzz;

use InvalidArgumentException;
use RuntimeException;
use Studio\Gesso\HttpMethod;
use Studio\Gesso\Internal\ObservedResponse;
use Studio\Gesso\Spec\OpenApiOperationResolver;
use Studio\Gesso\Spec\OpenApiSpecLoader;
use Throwable;

use function count;
use function crc32;
use function implode;
use function in_array;
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
    use RunsOperationHooks;
    use SelectsExploredOperations;

    /** @var null|list<int> */
    private ?array $negativeExpectedStatusClasses = null;

    /** @var null|callable(ExploredCase, ExploredOperation): mixed */
    private $mutateCase;

    /** @var null|callable(ExploredCase, ExploredOperation): mixed */
    private $dispatch;

    /** @var null|callable(mixed, ExploredCase, ExploredOperation): void */
    private $assertResponse;

    /** @var null|callable(mixed): mixed */
    private $observeResponse;
    private int $maxLinkDepth = 0;
    private int $followedLinks = 0;
    private ?LinkFollower $linkFollower = null;

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

    /**
     * Opt in to following explicit response `links`: after each valid case,
     * every link declared on the observed response status is followed with a
     * generated request for the target operation whose linked parameters and
     * body are evaluated from the parent exchange, up to `$maxDepth` hops.
     * Each (parent, follow-up) pair is checked for {@see LinkChainCheck}
     * violations. `$observe` converts whatever dispatchUsing() returned into a
     * PSR-7 ResponseInterface or `['status' => int, 'headers' => array, 'body' => string]`.
     *
     * Specs without `links`, plans that never call this, and negativeCases()
     * plans behave exactly as before.
     *
     * @param callable(mixed): mixed $observe
     */
    public function followLinksUsing(callable $observe, int $maxDepth = 2): self
    {
        if ($maxDepth < 1) {
            throw new InvalidArgumentException(sprintf('followLinksUsing() needs a maxDepth of at least 1, got %d.', $maxDepth));
        }

        $this->observeResponse = $observe;
        $this->maxLinkDepth = $maxDepth;

        return $this;
    }

    public function assertResponses(): SpecExplorationSummary
    {
        if ($this->dispatch === null) {
            throw new InvalidArgumentException('Whole-spec exploration requires dispatchUsing() before assertResponses().');
        }

        $spec = OpenApiSpecLoader::load($this->specName);
        $paths = SpecPathsPreflight::validatedPaths($this->specName, $spec);
        $this->followedLinks = 0;
        $this->linkFollower = $this->observeResponse !== null && $this->negativeExpectedStatusClasses === null
            ? new LinkFollower($this->specName, $spec)
            : null;
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

        return new SpecExplorationSummary($executedOperations, $executedCases, $operations, $skips, $this->followedLinks);
    }

    private static function chainLine(int $position, string $link, ExploredCase $case, string $status): string
    {
        return sprintf('%d. [%s] %s %s → %s', $position, $link, $case->method->value, $case->uri(), $status);
    }

    private function runOperation(
        ExploredOperation $operation,
        ExplorationCases $cases,
        int &$executedCases,
    ): void {
        $this->runWithOperationHooks($operation, true, function () use ($operation, $cases, &$executedCases): void {
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

                // After the try/catch so a chain failure keeps its own ordered-chain message.
                if ($this->linkFollower !== null) {
                    $observed = $this->observe($response, $operation, $case, $caseIndex);
                    $root = sprintf('1. %s %s → %d (case %d, operation seed %d)', $case->method->value, $case->uri(), $observed->statusCode, $caseIndex, $operation->seed);
                    $this->followLinks($this->linkFollower, $operation, $caseIndex, $case, $observed, 1, [$root], [$case->method->value . ' ' . $case->matchedPath]);
                }
            }
        });
    }

    /**
     * @param list<string> $chain ordered chain lines so far
     * @param list<string> $visited `METHOD path` pairs already in the chain
     */
    private function followLinks(
        LinkFollower $follower,
        ExploredOperation $root,
        int $caseIndex,
        ExploredCase $parent,
        ObservedResponse $parentResponse,
        int $depth,
        array $chain,
        array $visited,
    ): void {
        foreach ($follower->links($parent->method->value, $parent->matchedPath, $parentResponse->statusCode) as $name => $link) {
            $target = $follower->target($name, $link);
            $operation = $this->operationFromDeclaration($target['path'], $target['method'], $target['operation']);
            $seed = crc32(implode("\0", [(string) $root->seed, (string) $caseIndex, ...$chain, $name])) & 0x7fffffff;
            $child = $follower->generate($name, $target, $seed);

            try {
                if ($this->mutateCase !== null) {
                    $mutated = ($this->mutateCase)($child, $operation);
                    if (!$mutated instanceof ExploredCase) {
                        throw new InvalidArgumentException('mutateCasesUsing() must return an ExploredCase.');
                    }
                    $child = $mutated;
                }

                // Authored link values win over mutations.
                $child = $follower->apply($name, $link, $target, $child, $parent, $parentResponse);
                $response = ($this->dispatch)($child, $operation);
                if ($this->assertResponse !== null) {
                    ($this->assertResponse)($response, $child, $operation);
                }
                $observed = ObservedResponse::from(($this->observeResponse ?? static fn(mixed $r): mixed => $r)($response));
            } catch (Throwable $e) {
                throw new RuntimeException($this->chainFailureMessage('Link chain failed: ' . $e->getMessage(), $root, $caseIndex, [...$chain, self::chainLine(count($chain) + 1, $name, $child, '(failed)')], $child), 0, $e);
            }

            $this->followedLinks++;
            $chainSoFar = [...$chain, self::chainLine(count($chain) + 1, $name, $child, (string) $observed->statusCode)];
            $check = LinkFollower::check($parent->method, $parentResponse->statusCode, $child->method, $observed->statusCode);
            if ($check !== null) {
                throw new RuntimeException($this->chainFailureMessage(sprintf("Link chain check '%s' failed.", $check->value), $root, $caseIndex, $chainSoFar, $child));
            }

            $key = $child->method->value . ' ' . $child->matchedPath;
            if ($depth < $this->maxLinkDepth && !in_array($key, $visited, true)) {
                $this->followLinks($follower, $root, $caseIndex, $child, $observed, $depth + 1, $chainSoFar, [...$visited, $key]);
            }
        }
    }

    private function observe(mixed $response, ExploredOperation $operation, ExploredCase $case, int $caseIndex): ObservedResponse
    {
        try {
            return ObservedResponse::from(($this->observeResponse ?? static fn(mixed $r): mixed => $r)($response));
        } catch (Throwable $e) {
            throw new RuntimeException('followLinksUsing() observer failed: ' . $e->getMessage() . "\n" . $this->caseFailureMessage($operation, $case, $caseIndex), 0, $e);
        }
    }

    /**
     * @param list<string> $chain
     */
    private function chainFailureMessage(string $headline, ExploredOperation $root, int $caseIndex, array $chain, ExploredCase $last): string
    {
        return sprintf(
            "%s\nSpec: %s\nGlobal seed: %d\nChain:\n%s\nReplay: %s\nCurl: %s",
            $headline,
            $root->specName,
            $this->seed,
            implode("\n", $chain),
            $root->replaySnippet($caseIndex) . ' (the chain root; follow-ups are derived deterministically from it)',
            $last->curlSnippet(),
        );
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
