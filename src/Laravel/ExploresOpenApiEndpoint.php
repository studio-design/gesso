<?php

declare(strict_types=1);

namespace Studio\Gesso\Laravel;

use Closure;
use InvalidArgumentException;
use RuntimeException;
use Studio\Gesso\Fuzz\ExplorationCases;
use Studio\Gesso\Fuzz\GeneratedResponseCases;
use Studio\Gesso\Fuzz\OpenApiEndpointExplorer;
use Studio\Gesso\Fuzz\OpenApiResponseExplorer;
use Studio\Gesso\Fuzz\OpenApiResponseSpecExploration;
use Studio\Gesso\Fuzz\OpenApiSpecExploration;
use Studio\Gesso\Fuzz\OpenApiSpecExplorer;
use Studio\Gesso\Internal\Deprecations;
use Studio\Gesso\PHPUnit\CleanTraceAssert;

/**
 * Schema-driven request fuzzing trait — issue #136.
 *
 * Generates valid-boundary or targeted-invalid request inputs directly from
 * the OpenAPI spec, returning an iterable {@see ExplorationCases} collection
 * that the test author drives through any HTTP client of choice.
 * Each HTTP call still goes through the existing {@see ValidatesOpenApiSchema}
 * auto-assert hook, so the response side and coverage tracking happen
 * automatically.
 *
 * Spec-name resolution mirrors {@see ValidatesOpenApiSchema}:
 * `#[OpenApiSpec]` method/class attribute → `openApiSpec()` override →
 * `config('gesso.default_spec')`.
 *
 * See README "Schema-driven request fuzzing" for usage examples; the
 * {@see ExploresOpenApiEndpointTest} suite pins the supported behaviour.
 */
trait ExploresOpenApiEndpoint
{
    use ResolvesOpenApiSpec;

    /**
     * Generate $cases happy-path request inputs for the given operation.
     *
     * @param string $method HTTP verb (case-insensitive).
     * @param string $path Spec path (template form, e.g. `/v1/pets/{petId}`)
     *                     or a concrete URI matching one of the spec paths.
     * @param int $cases How many input shapes to produce. Default 30 keeps
     *                   CI runtime predictable; bump per-test when an
     *                   operation has high cardinality.
     * @param ?int $seed When supplied alongside `fakerphp/faker`, locks
     *                   generation to deterministic output. Without faker,
     *                   output is already deterministic regardless.
     */
    public function exploreEndpoint(
        string $method,
        string $path,
        int $cases = 30,
        ?int $seed = null,
    ): ExplorationCases {
        return $this->explore(
            static fn(string $specName) => OpenApiEndpointExplorer::explore($specName, $method, $path, $cases, $seed),
        );
    }

    /** @param list<int> $expectedStatusClasses */
    public function exploreInvalidEndpoint(
        string $method,
        string $path,
        array $expectedStatusClasses,
        int $cases = 30,
        ?int $seed = null,
    ): ExplorationCases {
        return $this->explore(static fn(string $specName) => OpenApiEndpointExplorer::exploreInvalid(
            $specName,
            $method,
            $path,
            $expectedStatusClasses,
            $cases,
            $seed,
        ));
    }

    /**
     * Generate branch-complete valid payloads for one response schema so a
     * generated SDK's decode/encode round trip can be exercised.
     */
    public function exploreResponseSchema(
        string $method,
        string $path,
        int $status,
        ?string $contentType = null,
        ?int $seed = null,
        int $extraCases = 0,
    ): GeneratedResponseCases {
        return $this->explore(static fn(string $specName) => OpenApiResponseExplorer::explore(
            $specName,
            $method,
            $path,
            $status,
            $contentType,
            $seed,
            $extraCases,
        ));
    }

    /**
     * Build a deterministic spec-wide generated SDK response round-trip plan.
     */
    public function exploreResponseSpec(int $seed = 1, int $extraCases = 0): OpenApiResponseSpecExploration
    {
        return $this->explore(
            static fn(string $specName) => OpenApiResponseExplorer::exploreSpec($specName, $seed, $extraCases),
        );
    }

    /**
     * Build a deterministic whole-spec exploration plan.
     */
    public function exploreSpec(int $casesPerOperation = 30, int $seed = 1): OpenApiSpecExploration
    {
        return $this->explore(
            static fn(string $specName) => OpenApiSpecExplorer::explore($specName, $casesPerOperation, $seed),
        );
    }

    /**
     * Resolve the spec name and run one explorer call, turning an empty spec
     * name or a loader/explorer exception into a clean assertion failure.
     * RuntimeException covers OpenApiSpecLoader failures
     * (InvalidOpenApiSpecException, SpecFileNotFoundException, etc.) —
     * without this, a missing or malformed spec leaks the loader's raw stack
     * trace into PHPUnit instead of a clean assertion.
     *
     * @template T
     *
     * @param Closure(string): T $run
     *
     * @return T
     */
    private function explore(Closure $run): mixed
    {
        $specName = $this->resolveOpenApiSpec();
        if ($specName === '') {
            CleanTraceAssert::fail(self::EMPTY_SPEC_MESSAGE);
        }

        try {
            return $run($specName);
        } catch (InvalidArgumentException|RuntimeException $e) {
            CleanTraceAssert::fail($e->getMessage());
        }
    }

    /**
     * @deprecated Use PHPUnit's `$this->fail()`. Kept because private members
     *             of this public trait are frozen by the v2 compatibility
     *             policy; nothing in Gesso calls it. Removed in Gesso 3.0.
     */
    private function failExplore(string $message): never
    {
        Deprecations::notice(
            id: 'trait.explores_open_api_endpoint.fail_explore',
            subject: 'The private trait helper failExplore()',
            replacement: '$this->fail() (PHPUnit)',
            removedIn: '3.0',
        );

        CleanTraceAssert::fail($message);
    }
}
