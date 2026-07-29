<?php

declare(strict_types=1);

namespace Studio\Gesso\Psr7;

use Closure;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\AssertionFailedError;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Studio\Gesso\Baseline\ViolationBaselineCollector;
use Studio\Gesso\Baseline\ViolationBaselineEnforcer;
use Studio\Gesso\Internal\CurlCommandFormatter;
use Studio\Gesso\Internal\FailureOutput;
use Studio\Gesso\Internal\StackTraceFilter;
use Studio\Gesso\OpenApiRequestValidator;
use Studio\Gesso\OpenApiResponseValidator;
use Studio\Gesso\OpenApiValidationResult;
use Studio\Gesso\Spec\OpenApiSpecResolver;
use Studio\Gesso\ValidationOutput;
use Studio\Gesso\ValidationOutputFormat;
use Throwable;

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
            $request->getMethod(),
            $request->getUri()->getPath() ?: '/',
            sprintf(
                'OpenAPI PSR-7 request validation failed for %s %s',
                $request->getMethod(),
                $request->getUri()->getPath() ?: '/',
            ),
            fn(): string => $this->psr7ReproduceCommand($request),
        );
    }

    public function assertPsr7ResponseMatchesOpenApiSchema(
        RequestInterface $request,
        ResponseInterface $response,
    ): void {
        $result = $this->psr7Validator()->validateResponse($request, $response);

        $this->assertPsr7Result(
            $result,
            $request->getMethod(),
            $request->getUri()->getPath() ?: '/',
            sprintf(
                'OpenAPI PSR-7 response validation failed for %s %s',
                $request->getMethod(),
                $request->getUri()->getPath() ?: '/',
            ),
            fn(): string => $this->psr7ReproduceCommand($request),
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
            $method,
            $requestPath,
            sprintf('OpenAPI PSR-7 response validation failed for %s %s', $method, $requestPath),
            static fn(): string => CurlCommandFormatter::format($method, $requestPath, [], null, null),
        );
    }

    public function assertPsr7ExchangeMatchesOpenApiSchema(
        RequestInterface $request,
        ResponseInterface $response,
    ): void {
        $result = $this->psr7Validator()->validateExchange($request, $response);

        if ($result->isValid()) {
            $this->assertPsr7(true, '');

            return;
        }

        $collector = ViolationBaselineCollector::current();
        if ($collector !== null) {
            $method = $request->getMethod();
            $path = $request->getUri()->getPath() ?: '/';
            foreach ([$result->requestResult(), $result->responseResult()] as $sideResult) {
                if (!$sideResult->isValid()) {
                    $collector->recordResult((string) $this->cachedPsr7SpecName, $sideResult, $method, $path);
                }
            }
            $this->assertPsr7(true, '');

            return;
        }

        $enforcer = ViolationBaselineEnforcer::current();
        if ($enforcer !== null) {
            $method = $request->getMethod();
            $path = $request->getUri()->getPath() ?: '/';
            // Every failing side is checked — no short-circuit — so hits are
            // marked on baselined sides even when another side fails loud.
            $allSuppressed = true;
            foreach ([$result->requestResult(), $result->responseResult()] as $sideResult) {
                if (
                    !$sideResult->isValid() &&
                    !$enforcer->suppressesResult((string) $this->cachedPsr7SpecName, $sideResult, $method, $path)
                ) {
                    $allSuppressed = false;
                }
            }
            if ($allSuppressed) {
                $this->assertPsr7(true, '');

                return;
            }
        }

        $message = FailureOutput::composeExchange(
            sprintf(
                'OpenAPI PSR-7 exchange validation failed for %s %s (spec: %s)',
                $request->getMethod(),
                $request->getUri()->getPath() ?: '/',
                $this->cachedPsr7SpecName,
            ),
            ['request' => $result->requestResult(), 'response' => $result->responseResult()],
            fn(): string => $this->psr7ReproduceCommand($request),
        );

        // See assertPsr7Result(): json mode must end with parseable documents.
        if (ValidationOutput::format() === ValidationOutputFormat::Json) {
            $this->failPsr7($message);
        }

        $this->assertPsr7(false, $message);
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
                maxErrors: ViolationBaselineCollector::uncap($this->openApiMaxErrors()),
                skipResponseCodes: $this->openApiSkipResponseCodes(),
                skipRequestValidationResponseCodes: $this->openApiSkipRequestValidationResponseCodes(),
            );
            $this->cachedPsr7SpecName = $specName;
        }

        return $this->cachedPsr7Validator;
    }

    /**
     * The reproduce command is a closure so the request body stream is only
     * touched when the assertion actually fails; a passing assertion must not
     * observe or move the caller's stream cursor.
     *
     * During a baseline generation run (issue #402) the failure is demoted
     * instead: fingerprints are recorded and the assertion passes so the
     * whole suite completes in one run. During an enforcement run the
     * failure is suppressed only when every issue is baselined; any new
     * violation falls through to the full, unmodified failure.
     *
     * @param Closure(): string $reproduceCommand
     */
    private function assertPsr7Result(
        OpenApiValidationResult $result,
        string $method,
        string $path,
        string $prefix,
        Closure $reproduceCommand,
    ): void {
        if ($result->isValid()) {
            $this->assertPsr7(true, '');

            return;
        }

        $collector = ViolationBaselineCollector::current();
        if ($collector !== null) {
            $collector->recordResult((string) $this->cachedPsr7SpecName, $result, $method, $path);
            $this->assertPsr7(true, '');

            return;
        }

        $enforcer = ViolationBaselineEnforcer::current();
        if ($enforcer !== null && $enforcer->suppressesResult((string) $this->cachedPsr7SpecName, $result, $method, $path)) {
            $this->assertPsr7(true, '');

            return;
        }

        $message = FailureOutput::compose(
            sprintf('%s (spec: %s)', $prefix, $this->cachedPsr7SpecName),
            $result,
            $reproduceCommand,
        );

        // Json mode must end with the parseable document, so fail without
        // PHPUnit's "Failed asserting that false is true." suffix; text mode
        // keeps the historical assertTrue() message byte-for-byte.
        if (ValidationOutput::format() === ValidationOutputFormat::Json) {
            $this->failPsr7($message);
        }

        $this->assertPsr7(false, $message);
    }

    private function psr7ReproduceCommand(RequestInterface $request): string
    {
        $body = null;
        $stream = $request->getBody();
        if ($stream->isSeekable()) {
            $position = null;

            try {
                $position = $stream->tell();
                $stream->rewind();
                $body = $stream->getContents();
            } catch (Throwable) {
                // An unreadable stream must not replace the real validation
                // failure; degrade to a body-less command.
                $body = null;
            } finally {
                if ($position !== null) {
                    try {
                        $stream->seek($position);
                    } catch (Throwable) {
                        // Restoring the cursor is best-effort; the command
                        // must still render.
                    }
                }
            }
        }

        return CurlCommandFormatter::format(
            $request->getMethod(),
            (string) $request->getUri(),
            $request->getHeaders(),
            $body,
            $request->getHeaderLine('Content-Type') ?: null,
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
