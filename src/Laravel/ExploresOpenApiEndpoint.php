<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Laravel;

use InvalidArgumentException;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\AssertionFailedError;
use RuntimeException;
use Studio\OpenApiContractTesting\Fuzz\ExplorationCases;
use Studio\OpenApiContractTesting\Fuzz\OpenApiEndpointExplorer;
use Studio\OpenApiContractTesting\Internal\StackTraceFilter;
use Studio\OpenApiContractTesting\Spec\OpenApiSpecResolver;

use function is_string;

/**
 * Schema-driven request fuzzing trait — issue #136.
 *
 * Generates N happy-path request inputs for a single (method, path) operation
 * directly from the OpenAPI spec, returning an iterable {@see ExplorationCases}
 * collection that the test author drives through any HTTP client of choice.
 * Each HTTP call still goes through the existing {@see ValidatesOpenApiSchema}
 * auto-assert hook, so the response side and coverage tracking happen
 * automatically.
 *
 * Spec-name resolution mirrors {@see ValidatesOpenApiSchema}:
 * `#[OpenApiSpec]` method/class attribute → `openApiSpec()` override →
 * `config('openapi-contract-testing.default_spec')`.
 *
 * See README "Schema-driven request fuzzing" for usage examples; the
 * {@see ExploresOpenApiEndpointTest} suite pins the supported behaviour.
 */
trait ExploresOpenApiEndpoint
{
    use OpenApiSpecResolver;

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
        $specName = $this->resolveOpenApiSpec();
        if (!is_string($specName) || $specName === '') {
            $this->failExplore(
                'openApiSpec() must return a non-empty spec name, but an empty string was returned. '
                . 'Either add #[OpenApiSpec(\'your-spec\')] to your test class or method, '
                . 'override openApiSpec() in your test class, or set the "default_spec" key '
                . 'in config/openapi-contract-testing.php.',
            );
        }

        try {
            return OpenApiEndpointExplorer::explore($specName, $method, $path, $cases, $seed);
        } catch (InvalidArgumentException|RuntimeException $e) {
            // RuntimeException covers OpenApiSpecLoader failures
            // (InvalidOpenApiSpecException, SpecFileNotFoundException, etc.)
            // — without this, a missing or malformed spec leaks the loader's
            // raw stack trace into PHPUnit instead of a clean assertion.
            $this->failExplore($e->getMessage());
        }
    }

    protected function openApiSpecFallback(): string
    {
        return $this->openApiSpec();
    }

    protected function openApiSpec(): string
    {
        $spec = config('openapi-contract-testing.default_spec');

        if (!is_string($spec) || $spec === '') {
            return '';
        }

        return $spec;
    }

    /**
     * Strip vendor frames so the failure points at the user's test method
     * rather than the trait's call site. The pattern matches
     * {@see ValidatesOpenApiSchema} so the two failure shapes stay consistent.
     */
    private function failExplore(string $message): never
    {
        try {
            Assert::fail($message);
        } catch (AssertionFailedError $e) {
            StackTraceFilter::rethrowWithCleanTrace($e);
        }
    }
}
