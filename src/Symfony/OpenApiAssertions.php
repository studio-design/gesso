<?php

declare(strict_types=1);

namespace Studio\Gesso\Symfony;

use const JSON_THROW_ON_ERROR;

use JsonException;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\AssertionFailedError;
use Studio\Gesso\Coverage\OpenApiCoverageTracker;
use Studio\Gesso\DecodedBody;
use Studio\Gesso\HttpMethod;
use Studio\Gesso\Internal\StackTraceFilter;
use Studio\Gesso\OpenApiRequestValidator;
use Studio\Gesso\OpenApiResponseValidator;
use Studio\Gesso\Spec\OpenApiSpecLoader;
use Studio\Gesso\Spec\OpenApiSpecResolver;
use Studio\Gesso\Validation\Strict\StrictRequiredTracker;
use Studio\Gesso\Validation\Support\ContentTypeMatcher;
use Symfony\Component\BrowserKit\Exception\BadMethodCallException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelBrowser;

use function array_merge;
use function json_decode;
use function sprintf;
use function strtolower;
use function strtoupper;
use function var_export;

/**
 * OpenAPI contract-testing assertions for Symfony HttpFoundation.
 *
 * Mix this trait into a Symfony test case (typically a `WebTestCase`
 * subclass, but any PHPUnit `TestCase` works) to validate HttpFoundation
 * `Request` / `Response` objects against an OpenAPI 3.0 / 3.1 spec. It is the
 * Symfony counterpart of the Laravel `ValidatesOpenApiSchema` trait and
 * shares the same {@see OpenApiResponseValidator} / {@see OpenApiRequestValidator}
 * engine and {@see OpenApiCoverageTracker} coverage recording.
 *
 * Unlike the Laravel adapter there is no auto-assert hook — Symfony has no
 * equivalent of `MakesHttpRequests::createTestResponse()` — so every check is
 * an explicit call. The spec name is resolved via {@see OpenApiSpecResolver}:
 * a `#[OpenApiSpec]` attribute on the method or class, otherwise the
 * user-overridable {@see self::openApiSpec()} hook.
 *
 * Spec files are still discovered through {@see OpenApiSpecLoader},
 * configured either by the PHPUnit extension (`spec_base_path` in
 * `phpunit.xml`) or a direct `OpenApiSpecLoader::configure()` call.
 *
 * Example:
 *
 * ```php
 * use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
 * use Studio\Gesso\Attribute\OpenApiSpec;
 * use Studio\Gesso\Symfony\OpenApiAssertions;
 *
 * #[OpenApiSpec('front')]
 * final class PetsTest extends WebTestCase
 * {
 *     use OpenApiAssertions;
 *
 *     public function test_list_pets(): void
 *     {
 *         $client = static::createClient();
 *         $client->request('GET', '/api/v1/pets');
 *
 *         $this->assertClientMatchesOpenApiSchema($client);
 *     }
 * }
 * ```
 */
trait OpenApiAssertions
{
    use OpenApiSpecResolver;

    /**
     * Validators are cached per test-case instance — not statically as in the
     * Laravel adapter. PHPUnit builds a fresh TestCase per test method, so
     * instance scope already gives per-test isolation with no reset hook.
     */
    private ?OpenApiResponseValidator $cachedSymfonyResponseValidator = null;
    private ?OpenApiRequestValidator $cachedSymfonyRequestValidator = null;

    /**
     * Validate a Symfony `Response` against the OpenAPI spec.
     *
     * The HTTP method and request path are read from the supplied `Request`
     * (a `Response` carries neither). The endpoint is recorded as covered for
     * any matched spec path, mirroring the Laravel adapter.
     *
     * @param string[] $extraSkipResponseCodes additional status-code regex
     *                                         patterns (without delimiters or anchors) to skip body validation
     *                                         for. When non-empty they are merged with
     *                                         {@see OpenApiResponseValidator::DEFAULT_SKIP_RESPONSE_CODES} into a
     *                                         one-off validator for this call only; an empty array reuses the
     *                                         cached validator unchanged.
     */
    public function assertResponseMatchesOpenApiSchema(
        Request $request,
        Response $response,
        array $extraSkipResponseCodes = [],
    ): void {
        $method = $this->resolveSymfonyHttpMethod($request);
        $path = $request->getPathInfo();
        $specName = $this->resolveSymfonyOpenApiSpec();

        $content = $response->getContent();
        if ($content === false) {
            $this->failOpenApi(
                'OpenAPI contract testing requires buffered responses, but Response::getContent() '
                . 'returned false (streamed response?).',
            );
        }

        $contentType = $response->headers->get('Content-Type') ?? '';

        // A one-off validator when per-call skip codes are present bypasses the
        // cached instance so test-local codes don't leak into later calls.
        $validator = $extraSkipResponseCodes === []
            ? $this->symfonyResponseValidator()
            : new OpenApiResponseValidator(
                strictRequiredTracker: StrictRequiredTracker::current(),
                maxErrors: $this->openApiMaxErrors(),
                skipResponseCodes: array_merge(
                    OpenApiResponseValidator::DEFAULT_SKIP_RESPONSE_CODES,
                    $extraSkipResponseCodes,
                ),
            );

        $result = $validator->validate(
            $specName,
            $method->value,
            $path,
            $response->getStatusCode(),
            $this->extractSymfonyJsonBody($content, $contentType, 'Response'),
            $contentType !== '' ? $contentType : null,
            $response->headers->all(),
        );

        if ($result->matchedPath() !== null) {
            OpenApiCoverageTracker::recordResponse(
                $specName,
                $method->value,
                $result->matchedPath(),
                $result->matchedStatusCode() ?? (string) $response->getStatusCode(),
                $result->matchedContentType(),
                schemaValidated: !$result->isSkipped(),
                skipReason: $result->skipReason(),
            );
        }

        $this->assertOpenApi(
            $result->isValid(),
            sprintf(
                "OpenAPI schema validation failed for %s %s (spec: %s):\n%s",
                $method->value,
                $path,
                $specName,
                $result->errorMessage(),
            ),
        );
    }

    /**
     * Validate a Symfony `Request` against the OpenAPI spec (path / query /
     * header / cookie / security / body parameters).
     *
     * When `$responseStatusCode` is supplied and matches a configured
     * skip-request pattern AND the spec documents that status for the
     * operation, a request-validation failure is downgraded to Skipped — the
     * documented-4xx escape hatch that lets "send invalid input → assert 422"
     * tests keep passing. {@see self::assertClientMatchesOpenApiSchema()}
     * forwards the response status automatically.
     */
    public function assertRequestMatchesOpenApiSchema(
        Request $request,
        ?int $responseStatusCode = null,
    ): void {
        $method = $this->resolveSymfonyHttpMethod($request);
        $path = $request->getPathInfo();
        $specName = $this->resolveSymfonyOpenApiSpec();

        $contentType = $request->headers->get('Content-Type') ?? '';

        $result = $this->symfonyRequestValidator()->validate(
            $specName,
            $method->value,
            $path,
            $request->query->all(),
            $request->headers->all(),
            $this->extractSymfonyJsonBody($request->getContent(), $contentType, 'Request'),
            $contentType !== '' ? $contentType : null,
            $request->cookies->all(),
            $responseStatusCode,
        );

        if ($result->matchedPath() !== null) {
            OpenApiCoverageTracker::recordRequest(
                $specName,
                $method->value,
                $result->matchedPath(),
                $result->isSkipped() ? $result->skipReason() : null,
            );
        }

        $this->assertOpenApi(
            $result->isValid(),
            sprintf(
                "OpenAPI request validation failed for %s %s (spec: %s):\n%s",
                $method->value,
                $path,
                $specName,
                $result->errorMessage(),
            ),
        );
    }

    /**
     * Validate both the request and the response of the last call made by a
     * Symfony test client (`KernelBrowser` / `HttpKernelBrowser`).
     *
     * The request is validated first so the documented-4xx downgrade can see
     * the response status, matching the ordering of the Laravel adapter's
     * auto-validate hook. Call `$client->request(...)` before this method —
     * otherwise the assertion fails with an actionable message rather than
     * surfacing a raw framework exception.
     *
     * @param string[] $extraSkipResponseCodes forwarded to
     *                                         {@see self::assertResponseMatchesOpenApiSchema()}
     */
    public function assertClientMatchesOpenApiSchema(
        HttpKernelBrowser $client,
        array $extraSkipResponseCodes = [],
    ): void {
        // getRequest() / getResponse() throw BadMethodCallException when no
        // request has been made yet. Convert it into a normal contract-test
        // failure so client misuse is reported the same way as every other
        // misuse in this trait (clean message, vendor frames stripped),
        // rather than leaking a vendor-framed PHPUnit error.
        try {
            $request = $client->getRequest();
            $response = $client->getResponse();
        } catch (BadMethodCallException $e) {
            $this->failOpenApi(
                'assertClientMatchesOpenApiSchema() needs a completed request, but the test client '
                . 'has not made one yet. Call $client->request(...) before asserting. '
                . '(' . $e->getMessage() . ')',
            );
        }

        $this->assertRequestMatchesOpenApiSchema($request, $response->getStatusCode());
        $this->assertResponseMatchesOpenApiSchema($request, $response, $extraSkipResponseCodes);
    }

    /**
     * User-overridable default spec name, consulted by
     * {@see OpenApiSpecResolver} when no `#[OpenApiSpec]` attribute is present.
     * Override in a base test case to pin a project-wide spec without an
     * attribute on every class.
     */
    protected function openApiSpec(): string
    {
        return '';
    }

    /**
     * Maximum number of validation errors reported per request / response.
     * Override to widen or narrow the cap (0 = unlimited).
     */
    protected function openApiMaxErrors(): int
    {
        return 20;
    }

    /**
     * Bridges {@see OpenApiSpecResolver}'s final fallback layer to the
     * user-overridable {@see self::openApiSpec()} hook.
     */
    protected function openApiSpecFallback(): string
    {
        return $this->openApiSpec();
    }

    private function resolveSymfonyHttpMethod(Request $request): HttpMethod
    {
        $method = HttpMethod::tryFrom(strtoupper($request->getMethod()));
        if ($method === null) {
            $this->failOpenApi(sprintf(
                'Request uses unsupported HTTP method %s. Supported methods: %s.',
                var_export($request->getMethod(), true),
                HttpMethod::listOfValues(),
            ));
        }

        return $method;
    }

    private function resolveSymfonyOpenApiSpec(): string
    {
        $specName = $this->resolveOpenApiSpec();
        if ($specName === '') {
            $this->failOpenApi(
                'No OpenAPI spec is configured for this test. Add #[OpenApiSpec(\'your-spec\')] to the '
                . 'test class or method, or override openApiSpec() to return the spec name.',
            );
        }

        return $specName;
    }

    private function symfonyResponseValidator(): OpenApiResponseValidator
    {
        return $this->cachedSymfonyResponseValidator ??= new OpenApiResponseValidator(
            strictRequiredTracker: StrictRequiredTracker::current(),
            maxErrors: $this->openApiMaxErrors(),
        );
    }

    private function symfonyRequestValidator(): OpenApiRequestValidator
    {
        // The documented-4xx downgrade is on by default here, matching the
        // Laravel adapter's `skip_request_validation_response_codes` default.
        return $this->cachedSymfonyRequestValidator ??= new OpenApiRequestValidator(
            maxErrors: $this->openApiMaxErrors(),
            skipRequestValidationResponseCodes: OpenApiRequestValidator::DEFAULT_SKIP_REQUEST_VALIDATION_RESPONSE_CODES,
        );
    }

    /**
     * Decode a JSON request / response body in the shape the validators
     * expect. Mirrors the Laravel adapter: the body is JSON-decoded only when
     * the Content-Type is a JSON media type (or absent); an empty body, or a
     * non-JSON Content-Type, yields an absent envelope. The JSON-ness test uses
     * {@see ContentTypeMatcher::isJsonContentType()} so this adapter and the
     * validator agree on exactly which media types count as JSON (issue #251).
     *
     * Issues #246 / #248: when the raw content is non-empty but decodes to the
     * literal JSON `null`, a present {@see DecodedBody} carrying `null` is
     * returned so the validator type-checks the value against the schema
     * rather than mistaking it for an absent body. Non-null decoded values
     * (scalars, arrays) are wrapped in a present envelope unchanged.
     *
     * @param string $subject either `Request` or `Response`, used only for the
     *                        error message when the body is not valid JSON
     */
    private function extractSymfonyJsonBody(string $content, string $contentType, string $subject): DecodedBody
    {
        if ($content === '') {
            return DecodedBody::absent();
        }

        // Non-JSON Content-Type: leave the body undecoded and report it absent.
        // The validator receives the Content-Type separately and decides the
        // contract verdict from it (issue #251).
        if ($contentType !== '' && !ContentTypeMatcher::isJsonContentType(
            ContentTypeMatcher::normalizeMediaType($contentType),
        )) {
            return DecodedBody::absent();
        }

        // The return is inside the try so its dependence on a successful
        // decode is local and explicit: failOpenApi() is `: never`, so the
        // catch cannot fall through to a use of an undefined $decoded.
        try {
            /** @var mixed $decoded */
            $decoded = json_decode($content, true, flags: JSON_THROW_ON_ERROR);

            return DecodedBody::present($decoded);
        } catch (JsonException $e) {
            $this->failOpenApi(sprintf(
                '%s body could not be parsed as JSON: %s%s',
                $subject,
                $e->getMessage(),
                $contentType === ''
                    ? sprintf(' (no Content-Type header was present on the %s)', strtolower($subject))
                    : '',
            ));
        }
    }

    /** Like Assert::fail() but with vendor frames stripped from the trace. */
    private function failOpenApi(string $message): never
    {
        try {
            Assert::fail($message);
        } catch (AssertionFailedError $e) {
            StackTraceFilter::rethrowWithCleanTrace($e);
        }
    }

    /** Like Assert::assertTrue() but with vendor frames stripped from the trace on failure. */
    private function assertOpenApi(bool $condition, string $message): void
    {
        try {
            Assert::assertTrue($condition, $message);
        } catch (AssertionFailedError $e) {
            StackTraceFilter::rethrowWithCleanTrace($e);
        }
    }
}
