<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Psr7;

use PHPUnit\Framework\Assert;
use PHPUnit\Framework\AssertionFailedError;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Studio\OpenApiContractTesting\Internal\StackTraceFilter;
use Studio\OpenApiContractTesting\OpenApiRequestValidator;
use Studio\OpenApiContractTesting\OpenApiResponseValidator;
use Studio\OpenApiContractTesting\OpenApiValidationResult;
use Studio\OpenApiContractTesting\Spec\OpenApiSpecResolver;

use function sprintf;

/**
 * PHPUnit assertions for PSR-7 request and response messages.
 *
 * Resolve the spec with #[OpenApiSpec] or by overriding openApiSpec(). For a
 * non-PHPUnit result API, instantiate {@see OpenApiPsr7Validator} directly.
 */
trait OpenApiAssertions
{
    use OpenApiSpecResolver;
    private ?OpenApiPsr7Validator $cachedPsr7Validator = null;
    private ?string $cachedPsr7SpecName = null;

    public function assertPsr7RequestMatchesOpenApiSchema(
        RequestInterface $request,
        ?int $responseStatusCode = null,
    ): void {
        $result = $this->psr7Validator()->validateRequest($request, $responseStatusCode);

        $this->assertPsr7Result(
            $result,
            sprintf(
                'OpenAPI PSR-7 request validation failed for %s %s',
                $request->getMethod(),
                $request->getUri()->getPath() ?: '/',
            ),
        );
    }

    public function assertPsr7ResponseMatchesOpenApiSchema(
        RequestInterface $request,
        ResponseInterface $response,
    ): void {
        $result = $this->psr7Validator()->validateResponse($request, $response);

        $this->assertPsr7Result(
            $result,
            sprintf(
                'OpenAPI PSR-7 response validation failed for %s %s',
                $request->getMethod(),
                $request->getUri()->getPath() ?: '/',
            ),
        );
    }

    public function assertPsr7ResponseForOperationMatchesOpenApiSchema(
        string $method,
        string $requestPath,
        ResponseInterface $response,
    ): void {
        $result = $this->psr7Validator()->validateResponseForOperation($method, $requestPath, $response);

        $this->assertPsr7Result(
            $result,
            sprintf('OpenAPI PSR-7 response validation failed for %s %s', $method, $requestPath),
        );
    }

    public function assertPsr7ExchangeMatchesOpenApiSchema(
        RequestInterface $request,
        ResponseInterface $response,
    ): void {
        $result = $this->psr7Validator()->validateExchange($request, $response);

        $this->assertPsr7(
            $result->isValid(),
            sprintf(
                "OpenAPI PSR-7 exchange validation failed for %s %s (spec: %s):\n%s",
                $request->getMethod(),
                $request->getUri()->getPath() ?: '/',
                $this->cachedPsr7SpecName,
                $result->errorMessage(),
            ),
        );
    }

    /** User-overridable fallback when no #[OpenApiSpec] attribute is present. */
    protected function openApiSpec(): string
    {
        return '';
    }

    protected function openApiMaxErrors(): int
    {
        return 20;
    }

    /** @return string[] */
    protected function openApiSkipResponseCodes(): array
    {
        return OpenApiResponseValidator::DEFAULT_SKIP_RESPONSE_CODES;
    }

    /** @return string[] */
    protected function openApiSkipRequestValidationResponseCodes(): array
    {
        return OpenApiRequestValidator::DEFAULT_SKIP_REQUEST_VALIDATION_RESPONSE_CODES;
    }

    protected function openApiSpecFallback(): string
    {
        return $this->openApiSpec();
    }

    private function psr7Validator(): OpenApiPsr7Validator
    {
        $specName = $this->resolveOpenApiSpec();
        if ($specName === '') {
            $this->failPsr7(
                'No OpenAPI spec is configured for this PSR-7 assertion. Add '
                . "#[OpenApiSpec('your-spec')] or override openApiSpec().",
            );
        }

        if ($this->cachedPsr7Validator === null || $this->cachedPsr7SpecName !== $specName) {
            $this->cachedPsr7Validator = new OpenApiPsr7Validator(
                $specName,
                maxErrors: $this->openApiMaxErrors(),
                skipResponseCodes: $this->openApiSkipResponseCodes(),
                skipRequestValidationResponseCodes: $this->openApiSkipRequestValidationResponseCodes(),
            );
            $this->cachedPsr7SpecName = $specName;
        }

        return $this->cachedPsr7Validator;
    }

    private function assertPsr7Result(OpenApiValidationResult $result, string $prefix): void
    {
        $this->assertPsr7(
            $result->isValid(),
            sprintf("%s (spec: %s):\n%s", $prefix, $this->cachedPsr7SpecName, $result->errorMessage()),
        );
    }

    private function failPsr7(string $message): never
    {
        try {
            Assert::fail($message);
        } catch (AssertionFailedError $e) {
            StackTraceFilter::rethrowWithCleanTrace($e);
        }
    }

    private function assertPsr7(bool $condition, string $message): void
    {
        try {
            Assert::assertTrue($condition, $message);
        } catch (AssertionFailedError $e) {
            StackTraceFilter::rethrowWithCleanTrace($e);
        }
    }
}
