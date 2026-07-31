<?php

declare(strict_types=1);

namespace Studio\Gesso\Fuzz;

use InvalidArgumentException;
use RuntimeException;
use Studio\Gesso\HttpMethod;
use Studio\Gesso\Spec\OpenApiOperationResolver;
use Studio\Gesso\Spec\OpenApiPathMatcher;
use Studio\Gesso\Spec\OpenApiSpecLoader;
use Throwable;

use function array_key_exists;
use function array_values;
use function count;
use function crc32;
use function implode;
use function in_array;
use function sprintf;

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

    /** @var array<string, ?OpenApiPathMatcher> single-template matchers for collision scans, null when uncompilable */
    private array $templateMatchers = [];

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
                    ContractCheck::UnsupportedMethod => $this->buildUnsupportedMethodProbe($check, $path, $pathItem, $matching, $derivedSeed, $skips, $paths),
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
     * `additionalOperations` names are never probed themselves (case-sensitive
     * custom methods), but every declared name counts as documented and is
     * excluded from the candidates.
     *
     * The concrete probe URI can also be an instance of a different documented
     * template (`/members/me` alongside `/members/{member_id}`), and the
     * application would route the probe to that template's operation. Methods
     * documented by any colliding template are therefore excluded too; when
     * none survive, the path is skipped instead of reporting a failure the
     * spec does not contain.
     *
     * @param array<string, mixed> $pathItem
     * @param non-empty-list<ExploredOperation> $matching
     * @param list<ContractCheckSkip> $skips
     * @param array<string, array<string, mixed>> $paths every documented path item, unfiltered
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
        array $paths,
    ): ?array {
        // Every declared method name counts as documented: fixed fields under
        // their canonical uppercase key, additionalOperations entries verbatim.
        // OAS 3.2 forbids additionalOperations entries that spell a fixed
        // method ("PUT"), but the runtime resolver honors them case-sensitively,
        // so the probe must too — otherwise a documented method would be
        // reported as an unsupported-method contract failure.
        $documented = [];
        foreach (OpenApiOperationResolver::declaredOperations($pathItem) as $declared) {
            $documented[] = $declared['method'];
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
                $concretePath = $sourceCase->withQuery([])->uri();
                $collisions = $this->collidingDocumentedMethods($path, $concretePath, $paths);

                $safeCandidates = [];
                foreach ($candidates as $candidate) {
                    if (!isset($collisions[$candidate->value])) {
                        $safeCandidates[] = $candidate;
                    }
                }
                if ($safeCandidates === []) {
                    $collisionDetails = [];
                    foreach ($candidates as $candidate) {
                        $collisionDetails[] = sprintf("%s is documented by '%s'", $candidate->value, $collisions[$candidate->value]);
                    }
                    $skips[] = new ContractCheckSkip($check, $path, null, sprintf(
                        "The concrete probe URI '%s' is also an instance of other documented path templates that declare every undocumented method for this path (%s); a probe would be routed to a documented operation and proves nothing.",
                        $concretePath,
                        implode(', ', $collisionDetails),
                    ));

                    return null;
                }

                return [
                    new ExploredCase(
                        null,
                        [],
                        [],
                        $sourceCase->pathParams,
                        $safeCandidates[$derivedSeed % count($safeCandidates)],
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

    /**
     * Methods that a probe against `$concretePath` cannot disprove: every
     * method documented by a path template other than `$probedPath` that the
     * concrete URI also matches.
     *
     * @param array<string, array<string, mixed>> $paths
     *
     * @return array<string, string> documented method name => first colliding template declaring it
     */
    private function collidingDocumentedMethods(string $probedPath, string $concretePath, array $paths): array
    {
        $collisions = [];
        foreach ($paths as $template => $pathItem) {
            if ($template === $probedPath) {
                continue;
            }

            $matcher = $this->templateMatcher($template);
            if ($matcher === null || $matcher->match($concretePath) === null) {
                continue;
            }

            foreach (OpenApiOperationResolver::declaredOperations($pathItem) as $declared) {
                $collisions[$declared['method']] ??= $template;
            }
        }

        return $collisions;
    }

    private function templateMatcher(string $template): ?OpenApiPathMatcher
    {
        if (!array_key_exists($template, $this->templateMatchers)) {
            try {
                $this->templateMatchers[$template] = new OpenApiPathMatcher([$template]);
            } catch (InvalidArgumentException) {
                // A template the runtime matcher refuses to compile (e.g.
                // duplicate placeholder names) cannot route a request, so it
                // cannot collide with a probe URI either.
                $this->templateMatchers[$template] = null;
            }
        }

        return $this->templateMatchers[$template];
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
