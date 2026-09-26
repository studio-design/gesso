<?php

declare(strict_types=1);

namespace Studio\Gesso\Symfony;

use Closure;
use JsonException;
use Studio\Gesso\Baseline\ViolationBaselineCollector;
use Studio\Gesso\Coverage\OpenApiCoverageTracker;
use Studio\Gesso\DecodedBody;
use Studio\Gesso\HttpMethod;
use Studio\Gesso\Internal\OpenApiAssertionCore;
use Studio\Gesso\OpenApiRequestValidator;
use Studio\Gesso\OpenApiResponseValidator;
use Studio\Gesso\OpenApiValidationResult;
use Studio\Gesso\Spec\OpenApiSpecLoader;
use Studio\Gesso\Spec\OpenApiSpecResolver;
use Studio\Gesso\Validation\Strict\StrictRequiredTracker;
use Symfony\Component\BrowserKit\Exception\BadMethodCallException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelBrowser;

use function array_merge;
use function is_string;
use function sprintf;
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
    use OpenApiAssertionCore;
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
                maxErrors: ViolationBaselineCollector::uncap($this->openApiMaxErrors()),
                skipResponseCodes: array_merge(
                    OpenApiResponseValidator::DEFAULT_SKIP_RESPONSE_CODES,
                    $extraSkipResponseCodes,
                ),
            );

        $decodeFailureDemoted = false;
        $decodedBody = $this->extractOrRecordBaselineViolation(
            fn(): DecodedBody => $this->extractSymfonyJsonBody($content, $contentType, 'Response'),
            $specName,
            $method->value,
            $path,
            'response.body',
            $decodeFailureDemoted,
        );

        $result = $validator->validate(
            $specName,
            $method->value,
            $path,
            $response->getStatusCode(),
            $decodedBody,
            $contentType !== '' ? $contentType : null,
            $response->headers->all(),
        );

        // Coverage recording happens inside OpenApiResponseValidator
        // (issue #535); the adapter no longer records a second observation.

        $this->assertSymfonyOpenApiResult(
            $result,
            $specName,
            $method->value,
            $path,
            sprintf('OpenAPI schema validation failed for %s %s (spec: %s)', $method->value, $path, $specName),
            fn(): string => $this->symfonyReproduceCommand($request),
            $decodeFailureDemoted ? 'response.body' : null,
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

        $decodeFailureDemoted = false;
        $decodedBody = $this->extractOrRecordBaselineViolation(
            fn(): DecodedBody => $this->extractSymfonyRequestBody($request, $contentType),
            $specName,
            $method->value,
            $path,
            'request.body',
            $decodeFailureDemoted,
        );

        // The raw wire form, NOT Request::getQueryString(): Symfony's
        // normalization re-encodes and sorts pairs, which would corrupt the
        // literal delimiters non-exploded query styles split on.
        $rawQueryString = $request->server->get('QUERY_STRING');
        $rawQueryString = is_string($rawQueryString) && $rawQueryString !== '' ? $rawQueryString : null;

        $result = $this->symfonyRequestValidator()->validate(
            $specName,
            $method->value,
            $path,
            $request->query->all(),
            $request->headers->all(),
            $decodedBody,
            $contentType !== '' ? $contentType : null,
            $request->cookies->all(),
            $responseStatusCode,
            $rawQueryString,
        );

        // Coverage recording happens inside OpenApiRequestValidator
        // (issue #535); the adapter no longer records a second observation.

        $this->assertSymfonyOpenApiResult(
            $result,
            $specName,
            $method->value,
            $path,
            sprintf('OpenAPI request validation failed for %s %s (spec: %s)', $method->value, $path, $specName),
            fn(): string => $this->symfonyReproduceCommand($request),
            $decodeFailureDemoted ? 'request.body' : null,
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

    private function symfonyReproduceCommand(Request $request): string
    {
        return $this->httpFoundationReproduceCommand($request);
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
        // Pin the default skip set explicitly (issue #502 review): the
        // process-wide `skip_response_codes` extension parameter targets the
        // framework-agnostic path only, and an omitted argument would now
        // consult it. Mirrors the request validator below and the one-off
        // extraSkipResponseCodes validator above.
        return $this->cachedSymfonyResponseValidator ??= new OpenApiResponseValidator(
            strictRequiredTracker: StrictRequiredTracker::current(),
            maxErrors: ViolationBaselineCollector::uncap($this->openApiMaxErrors()),
            skipResponseCodes: OpenApiResponseValidator::DEFAULT_SKIP_RESPONSE_CODES,
        );
    }

    private function symfonyRequestValidator(): OpenApiRequestValidator
    {
        // The documented-4xx downgrade is on by default here, matching the
        // Laravel adapter's `skip_request_validation_response_codes` default.
        return $this->cachedSymfonyRequestValidator ??= new OpenApiRequestValidator(
            maxErrors: ViolationBaselineCollector::uncap($this->openApiMaxErrors()),
            skipRequestValidationResponseCodes: OpenApiRequestValidator::DEFAULT_SKIP_REQUEST_VALIDATION_RESPONSE_CODES,
        );
    }

    /**
     * Share HttpFoundation extraction with the other framework adapter:
     * JSON retains object/array types and literal-null presence, form bodies
     * carry parsed fields or raw bytes, and opaque bodies carry presence only.
     * Keep JSON failures in this trait's assertion/baseline handling.
     */
    private function extractSymfonyRequestBody(Request $request, string $contentType): DecodedBody
    {
        try {
            return HttpFoundationBody::request($request, $contentType);
        } catch (JsonException $e) {
            $this->failOpenApi(HttpFoundationBody::parseFailure($e, $contentType, 'Request'));
        }
    }

    /**
     * Decode JSON with type provenance via the shared HttpFoundation helper.
     * Literal null remains present; absent and non-JSON responses retain the
     * existing absent-envelope policy for content negotiation.
     *
     * @param string $subject either Request or Response, for JSON error prose
     */
    private function extractSymfonyJsonBody(string $content, string $contentType, string $subject): DecodedBody
    {
        try {
            return HttpFoundationBody::json($content, $contentType);
        } catch (JsonException $e) {
            $this->failOpenApi(HttpFoundationBody::parseFailure($e, $contentType, $subject));
        }
    }

    /**
     * Frozen private name (docs/versioning.md); the body lives in
     * OpenApiAssertionCore::assertOpenApiResult().
     *
     * @param Closure(): string $reproduceCommand
     */
    private function assertSymfonyOpenApiResult(
        OpenApiValidationResult $result,
        string $specName,
        string $method,
        string $path,
        string $header,
        Closure $reproduceCommand,
        ?string $recordExcludeCategory = null,
    ): void {
        $this->assertOpenApiResult($result, $specName, $method, $path, $header, $reproduceCommand, $recordExcludeCategory);
    }
}
