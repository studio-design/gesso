<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Laravel;

use const E_USER_DEPRECATED;
use const FILTER_NULL_ON_FAILURE;
use const FILTER_VALIDATE_BOOLEAN;
use const JSON_THROW_ON_ERROR;
use const STDERR;

use Illuminate\Testing\TestResponse;
use InvalidArgumentException;
use JsonException;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\AssertionFailedError;
use RuntimeException;
use Studio\OpenApiContractTesting\Attribute\SkipOpenApi;
use Studio\OpenApiContractTesting\Coverage\OpenApiCoverageTracker;
use Studio\OpenApiContractTesting\HttpMethod;
use Studio\OpenApiContractTesting\Internal\StackTraceFilter;
use Studio\OpenApiContractTesting\OpenApiRequestValidator;
use Studio\OpenApiContractTesting\OpenApiResponseValidator;
use Studio\OpenApiContractTesting\SkipOpenApiResolver;
use Studio\OpenApiContractTesting\Spec\OpenApiPathMatcher;
use Studio\OpenApiContractTesting\Spec\OpenApiSpecLoader;
use Studio\OpenApiContractTesting\Spec\OpenApiSpecResolver;
use Studio\OpenApiContractTesting\Validation\Request\SecuritySchemeIntrospector;
use Studio\OpenApiContractTesting\Validation\Support\HeaderNormalizer;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use WeakMap;

use function array_key_first;
use function array_merge;
use function filter_var;
use function fwrite;
use function get_debug_type;
use function is_array;
use function is_int;
use function is_numeric;
use function is_string;
use function json_decode;
use function sprintf;
use function str_contains;
use function strtolower;
use function strtoupper;
use function trigger_error;
use function var_export;

trait ValidatesOpenApiSchema
{
    use OpenApiSpecResolver;
    use SkipOpenApiResolver;

    // Fixed dummy token injected when auto_inject_dummy_bearer is enabled and
    // the endpoint spec requires bearerAuth but the test did not set one.
    // A fixed string is sufficient because the value is never evaluated by
    // anything downstream — the inject only silences the spec's security
    // check. Making it configurable is a deliberate separate discussion.
    private const DUMMY_BEARER_TOKEN = 'test-token';

    // Counterpart to DUMMY_BEARER_TOKEN for apiKey schemes injected via
    // auto_inject_dummy_credentials. The value avoids `=` and `;` so it is
    // safe in cookie / query / header contexts without escaping concerns.
    private const DUMMY_API_KEY_VALUE = 'test-api-key';
    private static ?OpenApiResponseValidator $cachedValidator = null;
    private static ?int $cachedMaxErrors = null;
    private static ?OpenApiRequestValidator $cachedRequestValidator = null;
    private static ?int $cachedRequestMaxErrors = null;
    private static ?SecuritySchemeIntrospector $cachedSecuritySchemeIntrospector = null;

    /** @var null|string[] */
    private static ?array $cachedSkipResponseCodes = null;

    /** @var null|string[] */
    private static ?array $cachedSkipRequestValidationResponseCodes = null;

    /** @var null|WeakMap<TestResponse, array<string, true>> */
    private static ?WeakMap $validatedResponses = null;

    /**
     * Receives the warning emitted when a test marked #[SkipOpenApi] still
     * calls assertResponseMatchesOpenApiSchema() explicitly. The explicit
     * assertion always runs regardless — this is an advisory nudge that the
     * two signals contradict each other.
     *
     * Defaults to writing to STDERR and emitting an E_USER_DEPRECATED via
     * trigger_error(). Tests can swap it to capture warnings in-memory.
     *
     * @var null|callable(string): void
     */
    private static $skipWarningHandler;

    // Per-request skip flags set by withoutRequestValidation() /
    // withoutResponseValidation() / withoutValidation(). Consumed (and reset)
    // on the next auto-assert attempt, so the flag covers exactly one HTTP
    // call. Instance-level because PHPUnit builds a fresh TestCase per
    // method — this gives us natural per-test isolation.
    private bool $skipNextRequestValidation = false;
    private bool $skipNextResponseValidation = false;

    // Per-request additional response-code skip patterns set by
    // skipResponseCode(). Merged with the config-level skip set when building
    // the validator, then consumed (reset) on the next auto-assert attempt.
    // Patterns are stored raw (without delimiters/anchors); the validator
    // anchors them when compiling.
    /** @var string[] */
    private array $skipNextResponseCodes = [];

    /**
     * Drop the per-process validator cache so the next assertion rebuilds
     * with current config. Intended for test isolation when multiple
     * test classes share the same trait but want different settings.
     *
     * @internal
     */
    public static function resetValidatorCache(): void
    {
        self::$cachedValidator = null;
        self::$cachedMaxErrors = null;
        self::$cachedSkipResponseCodes = null;
        self::$cachedRequestValidator = null;
        self::$cachedRequestMaxErrors = null;
        self::$cachedSkipRequestValidationResponseCodes = null;
        self::$cachedSecuritySchemeIntrospector = null;
        self::$validatedResponses = null;
    }

    /**
     * Skips both request and response validation for the next HTTP call only.
     * The flag self-resets after one auto-assert attempt, so subsequent calls
     * are validated as usual.
     *
     * Scoped to auto-assert only — explicit calls to
     * assertResponseMatchesOpenApiSchema() still run, matching the convention
     * already established by #[SkipOpenApi].
     */
    public function withoutValidation(): static
    {
        $this->skipNextRequestValidation = true;
        $this->skipNextResponseValidation = true;

        return $this;
    }

    /**
     * Skips request validation for the next HTTP call only. The flag
     * self-resets after one auto-validate-request attempt. Scoped to
     * auto-validate only — the request validator is otherwise only exercised
     * from user code (no explicit-assertion counterpart on the request side).
     */
    public function withoutRequestValidation(): static
    {
        $this->skipNextRequestValidation = true;

        return $this;
    }

    /**
     * Skips response validation for the next HTTP call only. The flag
     * self-resets after one auto-assert attempt.
     *
     * Scoped to auto-assert only, matching the convention established by
     * #[SkipOpenApi] and `withoutValidation()`.
     */
    public function withoutResponseValidation(): static
    {
        $this->skipNextResponseValidation = true;

        return $this;
    }

    /**
     * Adds one or more response status codes to skip for the next auto-assert
     * HTTP call only. Merged with the config-level `skip_response_codes` set,
     * then consumed (reset) after one call — matching the per-request
     * consumption model of withoutValidation().
     *
     * - int: exact match (anchored for exact match, so `500` matches only "500").
     * - string: regex pattern (anchored automatically).
     * - array: expanded one level; each element must be int or string.
     *   Nested arrays are rejected with a clear error message rather than a
     *   raw TypeError.
     *
     * Scoped to auto-assert only. Explicit calls to
     * assertResponseMatchesOpenApiSchema() emit an advisory warning because
     * the per-request flag is silently ignored there.
     *
     * @param array<int|string>|int|string ...$codes
     *
     * @throws InvalidArgumentException when no codes are supplied, when an
     *                                  array argument is nested, or when an
     *                                  array element is not int|string.
     */
    public function skipResponseCode(array|int|string ...$codes): static
    {
        if ($codes === []) {
            throw new InvalidArgumentException(
                'skipResponseCode() requires at least one code.',
            );
        }

        $normalized = [];
        foreach ($codes as $index => $code) {
            if (is_array($code)) {
                foreach ($code as $innerIndex => $inner) {
                    if (!is_int($inner) && !is_string($inner)) {
                        throw new InvalidArgumentException(sprintf(
                            'skipResponseCode() array elements must be int or string; got %s at position [%d][%s]. '
                            . 'Nested arrays are not supported.',
                            get_debug_type($inner),
                            $index,
                            (string) $innerIndex,
                        ));
                    }
                    $normalized[] = self::normalizeSkipCode($inner);
                }
            } else {
                $normalized[] = self::normalizeSkipCode($code);
            }
        }

        if ($normalized === []) {
            throw new InvalidArgumentException(
                'skipResponseCode() requires at least one code, but all supplied arrays were empty.',
            );
        }

        foreach ($normalized as $code) {
            $this->skipNextResponseCodes[] = $code;
        }

        return $this;
    }

    /**
     * Overrides Laravel's MakesHttpRequests::createTestResponse hook so every
     * HTTP test call runs schema validation when auto_assert is enabled.
     * When the library is used outside Laravel, this method is never called.
     *
     * Method and path are resolved from the Request passed in by Laravel
     * rather than from app('request'), so auto-assert stays independent of
     * container state and sees the exact values the framework dispatched.
     *
     * @param Response $response
     * @param null|Request $request
     */
    protected function createTestResponse($response, $request = null): TestResponse
    {
        $testResponse = parent::createTestResponse($response, $request);

        $method = $request !== null ? HttpMethod::tryFrom(strtoupper($request->getMethod())) : null;
        $path = $request?->getPathInfo();

        // Request-side runs first so that the skipNextRequestValidation flag is
        // consumed at the HTTP boundary before the response hook gets a chance
        // to (defensively) clear it. The response status is forwarded so the
        // request validator can apply the documented-4xx downgrade (issue #179).
        $this->maybeAutoValidateOpenApiRequest($request, $method, $path, $response->getStatusCode());
        $this->maybeAutoAssertOpenApiSchema($testResponse, $method, $path);

        return $testResponse;
    }

    /**
     * Request-side counterpart to {@see self::maybeAutoAssertOpenApiSchema()}.
     * Invokes {@see OpenApiRequestValidator} against the Laravel-dispatched
     * Request when `auto_validate_request` is enabled, mirroring the
     * per-request opt-out (withoutRequestValidation / #[SkipOpenApi]) and
     * coverage-recording behavior already in place for responses.
     *
     * Auto-inject-dummy-bearer is a view-only rewrite: the Authorization
     * header is injected into the headers array we hand to the validator, not
     * into the Symfony Request itself. Laravel has already dispatched by the
     * time this method runs, so mutating the Request would be pointless — the
     * rewrite exists purely to keep the security check from false-failing on
     * tests that authenticate via actingAs() or middleware bypass.
     */
    protected function maybeAutoValidateOpenApiRequest(
        ?Request $request,
        ?HttpMethod $method = null,
        ?string $path = null,
        ?int $responseStatusCode = null,
    ): void {
        // Consume the per-request skip flag unconditionally at the HTTP call
        // boundary — see the analogous comment in maybeAutoAssertOpenApiSchema().
        $skipRequest = $this->skipNextRequestValidation;
        $this->skipNextRequestValidation = false;

        if (!$this->isAutoValidateRequestEnabled()) {
            return;
        }

        if ($skipRequest) {
            return;
        }

        // No request object or unrecognizable HTTP verb → nothing meaningful
        // to validate. Stay silent rather than fabricating an error; Laravel
        // only passes null/unknown in edge cases (direct TestResponse
        // construction outside MakesHttpRequests).
        if ($request === null || $method === null) {
            return;
        }

        if ($this->findSkipOpenApiAttribute() !== null) {
            return;
        }

        $specName = $this->resolveOpenApiSpec();
        if ($specName === '') {
            $this->failOpenApi(
                'openApiSpec() must return a non-empty spec name, but an empty string was returned. '
                . 'Either add #[OpenApiSpec(\'your-spec\')] to your test class or method, '
                . 'override openApiSpec() in your test class, or set the "default_spec" key '
                . 'in config/openapi-contract-testing.php.',
            );
        }

        $resolvedMethod = $method->value;
        $resolvedPath = $path ?? $request->getPathInfo();

        /** @var array<string, mixed> $queryParams */
        $queryParams = $request->query->all();
        /** @var array<string, array<int, null|string>> $headers */
        $headers = $request->headers->all();
        /** @var array<string, mixed> $cookies */
        $cookies = $request->cookies->all();
        $rawContentType = $request->headers->get('Content-Type');
        $contentType = is_string($rawContentType) ? $rawContentType : '';

        $body = $this->extractRequestBody($request, $contentType);

        foreach ($this->resolveAutoInjectCredentials($specName, $resolvedMethod, $resolvedPath, $headers, $cookies, $queryParams) as $credential) {
            if ($credential['kind'] === 'bearer') {
                // Lower-case the key so it round-trips through
                // HeaderNormalizer::normalize() the same way Symfony's
                // already-lowercased header bag does.
                $headers['authorization'] = ['Bearer ' . self::DUMMY_BEARER_TOKEN];

                continue;
            }

            $name = $credential['name'];
            switch ($credential['in']) {
                case 'header':
                    $headers[strtolower($name)] = [self::DUMMY_API_KEY_VALUE];

                    break;
                case 'cookie':
                    $cookies[$name] = self::DUMMY_API_KEY_VALUE;

                    break;
                case 'query':
                    $queryParams[$name] = self::DUMMY_API_KEY_VALUE;

                    break;
            }
        }

        $validator = $this->getOrCreateRequestValidator();
        $result = $validator->validate(
            $specName,
            $resolvedMethod,
            $resolvedPath,
            $queryParams,
            $headers,
            $body,
            $contentType !== '' ? $contentType : null,
            $cookies,
            $responseStatusCode,
        );

        // Record coverage when the request matched a spec path, same
        // tracking semantics as the response-side hook. The tracker is a set,
        // so this does not double-count when response auto-assert also fires.
        // When the validator downgraded to Skipped (documented-4xx case from
        // issue #179), forward the skip reason so the coverage report
        // surfaces the downgrade rather than reporting a clean validated
        // request.
        if ($result->matchedPath() !== null) {
            OpenApiCoverageTracker::recordRequest(
                $specName,
                $resolvedMethod,
                $result->matchedPath(),
                $result->isSkipped() ? $result->skipReason() : null,
            );
        }

        $this->assertOpenApi(
            $result->isValid(),
            "OpenAPI request validation failed for {$resolvedMethod} {$resolvedPath} (spec: {$specName}):\n"
            . $result->errorMessage(),
        );
    }

    protected function maybeAutoAssertOpenApiSchema(
        TestResponse $response,
        ?HttpMethod $method = null,
        ?string $path = null,
    ): void {
        // Consume per-request skip flags unconditionally, so they track the
        // HTTP call boundary regardless of auto_assert state. Without this,
        // a flag set before an auto_assert=false call would silently leak
        // into the next call after auto_assert flips on.
        $skipResponse = $this->skipNextResponseValidation;
        $extraSkipCodes = $this->skipNextResponseCodes;
        $this->skipNextRequestValidation = false;
        $this->skipNextResponseValidation = false;
        $this->skipNextResponseCodes = [];

        if (!$this->isAutoAssertEnabled()) {
            return;
        }

        if ($skipResponse) {
            return;
        }

        // #[SkipOpenApi] opts the test out of auto-assert entirely — no
        // validation, no coverage recording. Explicit calls to
        // assertResponseMatchesOpenApiSchema() still run but emit a warning.
        if ($this->findSkipOpenApiAttribute() !== null) {
            return;
        }

        $this->assertResponseMatchesOpenApiSchema($response, $method, $path, $extraSkipCodes);
    }

    protected function openApiSpec(): string
    {
        $spec = config('openapi-contract-testing.default_spec');

        if (!is_string($spec) || $spec === '') {
            return '';
        }

        return $spec;
    }

    protected function openApiSpecFallback(): string
    {
        return $this->openApiSpec();
    }

    /**
     * @param string[] $extraSkipResponseCodes Additional skip patterns for
     *                                         this call only; populated by maybeAutoAssertOpenApiSchema().
     *                                         Empty for explicit user calls.
     */
    protected function assertResponseMatchesOpenApiSchema(
        TestResponse $response,
        ?HttpMethod $method = null,
        ?string $path = null,
        array $extraSkipResponseCodes = [],
    ): void {
        $skipAttribute = $this->findSkipOpenApiAttribute();
        if ($skipAttribute !== null) {
            $this->emitSkipOpenApiWarning($skipAttribute);
        }

        // Pending per-request skip codes with no auto-assert in sight: the
        // user set skipResponseCode() but is calling explicit assert, which
        // ignores the flag by design. Warn and consume so the flag doesn't
        // leak into a later HTTP call and surprise the user.
        if ($extraSkipResponseCodes === [] && $this->skipNextResponseCodes !== []) {
            $this->emitSkipResponseCodeWarning();
            $this->skipNextResponseCodes = [];
        }

        $resolvedMethod = $method !== null ? $method->value : app('request')->getMethod();
        $resolvedPath = $path ?? app('request')->getPathInfo();

        $specName = $this->resolveOpenApiSpec();
        if ($specName === '') {
            $this->failOpenApi(
                'openApiSpec() must return a non-empty spec name, but an empty string was returned. '
                . 'Either add #[OpenApiSpec(\'your-spec\')] to your test class or method, '
                . 'override openApiSpec() in your test class, or set the "default_spec" key '
                . 'in config/openapi-contract-testing.php.',
            );
        }

        // Idempotency key includes the spec so that validating the same
        // response against a different spec (or a different method/path on
        // the same spec) still runs — auto-assert's no-op only applies to
        // exact repeats.
        $signature = $specName . ':' . $resolvedMethod . ' ' . $resolvedPath;

        if (self::isAlreadyValidated($response, $signature)) {
            return;
        }
        self::markValidated($response, $signature);

        $content = $response->getContent();
        if ($content === false) {
            $this->failOpenApi('OpenAPI contract testing requires buffered responses, but getContent() returned false (streamed response?).');
        }

        $contentType = $response->headers->get('Content-Type', '');

        // One-off validator when per-request skip codes are present — bypasses
        // the static cache so test-local codes don't pollute it (and so
        // cache-entry churn can't grow unbounded across tests).
        $validator = $extraSkipResponseCodes !== []
            ? $this->buildOneOffValidator($extraSkipResponseCodes)
            : $this->getOrCreateValidator();
        $result = $validator->validate(
            $specName,
            $resolvedMethod,
            $resolvedPath,
            $response->getStatusCode(),
            $this->extractJsonBody($response, $content, $contentType),
            $contentType !== '' ? $contentType : null,
            // HeaderNormalizer is idempotent; HeaderBag's already-lower-cased
            // keys pass through unchanged.
            $response->headers->all(),
        );

        // Record coverage for any matched endpoint, including those where body
        // validation was skipped (e.g. non-JSON content types). "Covered" means
        // the endpoint was exercised in a test, not that its body was validated.
        // Note: under auto_assert, this records coverage for every Laravel HTTP
        // call — including responses with no explicit contract-test intent.
        //
        // matchedStatusCode falls back to the literal status string when the
        // validator could not pick a spec key (e.g. "Status code N not defined"
        // failures) so the recording still pins the actually-exercised status.
        // Such recordings surface in `unexpectedObservations` rather than
        // counting toward coverage of declared spec entries.
        //
        // 204 and non-JSON still count as validated; only skip_response_codes
        // matches (isSkipped() === true) suppress body validation.
        if ($result->matchedPath() !== null) {
            OpenApiCoverageTracker::recordResponse(
                $specName,
                $resolvedMethod,
                $result->matchedPath(),
                $result->matchedStatusCode() ?? (string) $response->getStatusCode(),
                $result->matchedContentType(),
                schemaValidated: !$result->isSkipped(),
                skipReason: $result->skipReason(),
            );
        }

        $this->assertOpenApi(
            $result->isValid(),
            "OpenAPI schema validation failed for {$resolvedMethod} {$resolvedPath} (spec: {$specName}):\n"
            . $result->errorMessage(),
        );
    }

    private static function isAlreadyValidated(TestResponse $response, string $signature): bool
    {
        return self::$validatedResponses !== null &&
            isset(self::$validatedResponses[$response][$signature]);
    }

    private static function markValidated(TestResponse $response, string $signature): void
    {
        self::$validatedResponses ??= new WeakMap();
        $signatures = self::$validatedResponses[$response] ?? [];
        $signatures[$signature] = true;
        self::$validatedResponses[$response] = $signatures;
    }

    private static function normalizeSkipCode(int|string $code): string
    {
        // Int codes are returned as a bare string so the existing
        // OpenApiResponseValidator::compileSkipPatterns() pipeline wraps them
        // in ^(?:...)$ for exact-match semantics. Strings are already regex.
        return is_int($code) ? (string) $code : $code;
    }

    /**
     * Treat a slot as populated only when it carries a non-empty string value,
     * matching {@see Validation\Request\SecurityValidator::checkApiKeySatisfied()}'s
     * "missing" definition.
     *
     * Symfony's HeaderBag exposes header values as `list<?string>` (array
     * branch); CookieBag and ParameterBag (query) expose plain strings (scalar
     * branch). The array branch peels the first element before applying the
     * same string check so all three bag shapes converge on the same
     * "absent vs populated" verdict.
     */
    private static function slotIsAlreadyPopulated(mixed $value): bool
    {
        if ($value === null) {
            return false;
        }

        if (is_array($value)) {
            if ($value === []) {
                return false;
            }

            $first = $value[array_key_first($value)] ?? null;

            return is_string($first) && $first !== '';
        }

        return is_string($value) && $value !== '';
    }

    private function getOrCreateRequestValidator(): OpenApiRequestValidator
    {
        $resolvedMaxErrors = $this->resolveMaxErrors();
        $resolvedSkipCodes = $this->resolveSkipRequestValidationResponseCodes();

        if (
            self::$cachedRequestValidator === null ||
            self::$cachedRequestMaxErrors !== $resolvedMaxErrors ||
            self::$cachedSkipRequestValidationResponseCodes !== $resolvedSkipCodes
        ) {
            self::$cachedRequestValidator = new OpenApiRequestValidator(
                maxErrors: $resolvedMaxErrors,
                skipRequestValidationResponseCodes: $resolvedSkipCodes,
            );
            self::$cachedRequestMaxErrors = $resolvedMaxErrors;
            self::$cachedSkipRequestValidationResponseCodes = $resolvedSkipCodes;
        }

        return self::$cachedRequestValidator;
    }

    private function getSecuritySchemeIntrospector(): SecuritySchemeIntrospector
    {
        return self::$cachedSecuritySchemeIntrospector ??= new SecuritySchemeIntrospector();
    }

    /**
     * Determine which dummy credentials to splice into the validator's view of
     * the request. Returns the list of inject targets the caller should write —
     * empty list means "leave everything as the test set it up".
     *
     * Two modes coexist for backward compatibility:
     * - `auto_inject_dummy_credentials` (preferred) — injects bearer + every
     *   apiKey scheme (header / cookie / query) the operation declares.
     * - `auto_inject_dummy_bearer` (legacy) — injects bearer only.
     *
     * When both flags are true the credentials flag wins and the legacy flag
     * is bypassed; setting only the legacy flag preserves its narrower
     * bearer-only behavior exactly.
     *
     * Precondition: callers must already have gated on
     * `auto_validate_request` being on. Without that gate this method would
     * load the spec even when request validation is disabled, which is both
     * wasteful and surfacing-time-dependent (the validator's error path is
     * what makes the swallow below safe).
     *
     * Errors walking the spec (unreadable file, no matching path, missing
     * operation) fall through as "do not inject" — the validator will surface
     * the real error. We stay silent here so a broken spec produces exactly
     * one failure, not a confusing cascade.
     *
     * Slots already populated by the test (Authorization header, named cookie,
     * named query / header param) are filtered out — the test's intent always
     * wins, even when the supplied value is malformed and would fail the
     * security check on its own. Empty-string and empty-array values count as
     * absent, mirroring {@see Validation\Request\SecurityValidator::checkApiKeySatisfied()}'s
     * own missing-value definition so the inject path and the validation path
     * agree on what "no credential" looks like.
     *
     * @param array<string, mixed> $headers
     * @param array<string, mixed> $cookies
     * @param array<string, mixed> $queryParams
     *
     * @return list<array{kind: 'apiKey', in: 'cookie'|'header'|'query', name: string}|array{kind: 'bearer'}>
     */
    private function resolveAutoInjectCredentials(
        string $specName,
        string $method,
        string $path,
        array $headers,
        array $cookies,
        array $queryParams,
    ): array {
        $credentialsEnabled = $this->isAutoInjectDummyCredentialsEnabled();
        $legacyBearerEnabled = $this->isAutoInjectDummyBearerEnabled();

        if (!$credentialsEnabled && !$legacyBearerEnabled) {
            return [];
        }

        try {
            $spec = OpenApiSpecLoader::load($specName);
        } catch (RuntimeException) {
            // OpenApiSpecLoader throws RuntimeException on unreadable files,
            // malformed JSON/YAML, unsupported extensions, etc. Swallow those
            // and decline to inject — the validator re-loads the same spec
            // immediately after and will surface the real error. Broader
            // Throwable (TypeError, AssertionError, ...) keeps bubbling so
            // programmer bugs are not silently downgraded to "missing auth".
            return [];
        }

        $paths = $spec['paths'] ?? null;
        if (!is_array($paths)) {
            return [];
        }

        $matchedOperation = $this->findOperationForRequest($paths, $method, $path);
        if ($matchedOperation === null) {
            return [];
        }

        $introspector = $this->getSecuritySchemeIntrospector();

        if ($credentialsEnabled) {
            $candidates = $introspector->injectableCredentialsFor($spec, $matchedOperation);
        } else {
            $candidates = $introspector->endpointAcceptsBearer($spec, $matchedOperation)
                ? [['kind' => 'bearer']]
                : [];
        }

        if ($candidates === []) {
            return [];
        }

        $normalizedHeaders = HeaderNormalizer::normalize($headers);

        $filtered = [];
        foreach ($candidates as $candidate) {
            $existing = match ($candidate['kind']) {
                'bearer' => $normalizedHeaders['authorization'] ?? null,
                'apiKey' => match ($candidate['in']) {
                    'header' => $normalizedHeaders[strtolower($candidate['name'])] ?? null,
                    'cookie' => $cookies[$candidate['name']] ?? null,
                    'query' => $queryParams[$candidate['name']] ?? null,
                },
            };

            if (self::slotIsAlreadyPopulated($existing)) {
                continue;
            }

            $filtered[] = $candidate;
        }

        return $filtered;
    }

    /**
     * Locate the spec operation for (method, path) without re-running
     * OpenApiPathMatcher — the validator will match again internally when it
     * runs, and one extra literal lookup here avoids exposing its cache.
     * Only spec-declared paths are consulted; prefix stripping matches the
     * validator's behavior via OpenApiSpecLoader.
     *
     * @param array<string, mixed> $paths
     *
     * @return null|array<string, mixed>
     */
    private function findOperationForRequest(array $paths, string $method, string $path): ?array
    {
        $specPaths = [];
        foreach ($paths as $specPath => $_definition) {
            if (is_string($specPath)) {
                $specPaths[] = $specPath;
            }
        }

        $matcher = new OpenApiPathMatcher(
            $specPaths,
            OpenApiSpecLoader::getStripPrefixes(),
        );
        $matched = $matcher->match($path);
        if ($matched === null) {
            return null;
        }

        $pathSpec = $paths[$matched] ?? null;
        if (!is_array($pathSpec)) {
            return null;
        }

        $operation = $pathSpec[strtolower($method)] ?? null;

        return is_array($operation) ? $operation : null;
    }

    private function getOrCreateValidator(): OpenApiResponseValidator
    {
        $resolvedMaxErrors = $this->resolveMaxErrors();
        $resolvedSkipCodes = $this->resolveSkipResponseCodes();

        if (
            self::$cachedValidator === null ||
            self::$cachedMaxErrors !== $resolvedMaxErrors ||
            self::$cachedSkipResponseCodes !== $resolvedSkipCodes
        ) {
            self::$cachedValidator = new OpenApiResponseValidator(
                maxErrors: $resolvedMaxErrors,
                skipResponseCodes: $resolvedSkipCodes,
            );
            self::$cachedMaxErrors = $resolvedMaxErrors;
            self::$cachedSkipResponseCodes = $resolvedSkipCodes;
        }

        return self::$cachedValidator;
    }

    /**
     * @param string[] $extraSkipResponseCodes
     */
    private function buildOneOffValidator(array $extraSkipResponseCodes): OpenApiResponseValidator
    {
        return new OpenApiResponseValidator(
            maxErrors: $this->resolveMaxErrors(),
            skipResponseCodes: array_merge(
                $this->resolveSkipResponseCodes(),
                $extraSkipResponseCodes,
            ),
        );
    }

    private function resolveMaxErrors(): int
    {
        $maxErrors = config('openapi-contract-testing.max_errors', 20);

        return is_numeric($maxErrors) ? (int) $maxErrors : 20;
    }

    /** @return string[] */
    private function resolveSkipResponseCodes(): array
    {
        $raw = config('openapi-contract-testing.skip_response_codes', OpenApiResponseValidator::DEFAULT_SKIP_RESPONSE_CODES);

        if (!is_array($raw)) {
            $this->failOpenApi(sprintf(
                'openapi-contract-testing.skip_response_codes must be an array of regex patterns, got %s: %s.',
                get_debug_type($raw),
                var_export($raw, true),
            ));
        }

        $patterns = [];
        foreach ($raw as $index => $pattern) {
            if (!is_string($pattern)) {
                $this->failOpenApi(sprintf(
                    'openapi-contract-testing.skip_response_codes[%s] must be a string regex pattern, got %s.',
                    (string) $index,
                    get_debug_type($pattern),
                ));
            }
            if ($pattern === '') {
                $this->failOpenApi(sprintf(
                    'openapi-contract-testing.skip_response_codes[%s] must not be an empty string.',
                    (string) $index,
                ));
            }
            $patterns[] = $pattern;
        }

        return $patterns;
    }

    /** @return string[] */
    private function resolveSkipRequestValidationResponseCodes(): array
    {
        $raw = config(
            'openapi-contract-testing.skip_request_validation_response_codes',
            OpenApiRequestValidator::DEFAULT_SKIP_REQUEST_VALIDATION_RESPONSE_CODES,
        );

        if (!is_array($raw)) {
            $this->failOpenApi(sprintf(
                'openapi-contract-testing.skip_request_validation_response_codes must be an array of regex patterns, got %s: %s.',
                get_debug_type($raw),
                var_export($raw, true),
            ));
        }

        $patterns = [];
        foreach ($raw as $index => $pattern) {
            if (!is_string($pattern)) {
                $this->failOpenApi(sprintf(
                    'openapi-contract-testing.skip_request_validation_response_codes[%s] must be a string regex pattern, got %s.',
                    (string) $index,
                    get_debug_type($pattern),
                ));
            }
            if ($pattern === '') {
                $this->failOpenApi(sprintf(
                    'openapi-contract-testing.skip_request_validation_response_codes[%s] must not be an empty string.',
                    (string) $index,
                ));
            }
            $patterns[] = $pattern;
        }

        return $patterns;
    }

    private function emitSkipOpenApiWarning(SkipOpenApi $attribute): void
    {
        $reason = $attribute->reason;
        $message = sprintf(
            '%s::%s is marked #[SkipOpenApi%s] but called assertResponseMatchesOpenApiSchema() explicitly. '
            . 'The assertion will run. Remove the attribute or the explicit call to clarify intent.',
            static::class,
            $this->name(), // @phpstan-ignore method.notFound
            $reason !== '' ? sprintf('(reason: %s)', var_export($reason, true)) : '',
        );

        $this->dispatchSkipWarning($message);
    }

    private function emitSkipResponseCodeWarning(): void
    {
        $message = sprintf(
            '%s::%s set skipResponseCode() before calling assertResponseMatchesOpenApiSchema() explicitly. '
            . 'Per-request skip codes apply only to auto-assert; the explicit assertion ignores them. '
            . 'Remove the skipResponseCode() call or rely on auto-assert to clarify intent.',
            static::class,
            $this->name(), // @phpstan-ignore method.notFound
        );

        $this->dispatchSkipWarning($message);
    }

    private function dispatchSkipWarning(string $message): void
    {
        $handler = self::$skipWarningHandler;
        if ($handler !== null) {
            $handler($message);

            return;
        }

        // STDERR guarantees the message body is visible in CI regardless of
        // PHPUnit's `displayDetailsOnTestsThatTriggerDeprecations` setting —
        // without it, the default config would only show a "1 deprecation"
        // tally and hide the actual contradictory-intent message.
        fwrite(STDERR, sprintf("\n[openapi-contract-testing] %s\n", $message));
        // trigger_error still fires so PHPUnit counts the deprecation and
        // surfaces it in the run summary for downstream tools to detect.
        trigger_error($message, E_USER_DEPRECATED);
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

    private function isAutoAssertEnabled(): bool
    {
        return $this->resolveBoolConfig('auto_assert');
    }

    private function isAutoValidateRequestEnabled(): bool
    {
        return $this->resolveBoolConfig('auto_validate_request');
    }

    private function isAutoInjectDummyBearerEnabled(): bool
    {
        return $this->resolveBoolConfig('auto_inject_dummy_bearer');
    }

    private function isAutoInjectDummyCredentialsEnabled(): bool
    {
        return $this->resolveBoolConfig('auto_inject_dummy_credentials');
    }

    /**
     * Three-way coercion for a config flag: real bool passes through, null
     * coerces to false, string passes through FILTER_VALIDATE_BOOLEAN so
     * `'auto_X' => env('X')` (strings like "true" / "1") works without an
     * explicit cast. Anything else raises a loud PHPUnit failure so a typo
     * is not silently read as "off".
     */
    private function resolveBoolConfig(string $key): bool
    {
        $raw = config('openapi-contract-testing.' . $key, false);

        if ($raw === true) {
            return true;
        }
        if ($raw === false || $raw === null) {
            return false;
        }

        $parsed = filter_var($raw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($parsed === null) {
            $this->failOpenApi(sprintf(
                'openapi-contract-testing.%s must be a boolean (or a boolean-compatible value '
                . 'like "true"/"false"/"1"/"0"), got %s: %s.',
                $key,
                get_debug_type($raw),
                var_export($raw, true),
            ));
        }

        return $parsed;
    }

    /**
     * Extract the request body in the shape OpenApiRequestValidator expects.
     * Mirrors {@see self::extractJsonBody()} for the request side: parse JSON
     * only when the Content-Type claims it, stay `null` on empty or non-JSON
     * bodies so the validator decides whether the spec required one.
     */
    private function extractRequestBody(Request $request, string $contentType): mixed
    {
        $content = $request->getContent();
        if ($content === '') {
            return null;
        }

        if ($contentType !== '' && !str_contains(strtolower($contentType), 'json')) {
            return null;
        }

        try {
            /** @var mixed $decoded */
            $decoded = json_decode($content, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            $this->failOpenApi(
                'Request body could not be parsed as JSON: ' . $e->getMessage()
                . ($contentType === '' ? ' (no Content-Type header was present on the request)' : ''),
            );
        }

        return $decoded;
    }

    /** @return null|array<string, mixed> */
    private function extractJsonBody(TestResponse $response, string $content, string $contentType): ?array
    {
        if ($content === '') {
            return null;
        }

        // Non-JSON Content-Type: return null so the validator can decide
        // whether the spec requires a JSON body for this endpoint.
        if ($contentType !== '' && !str_contains(strtolower($contentType), 'json')) {
            return null;
        }

        try {
            return $response->json();
        } catch (JsonException $e) {
            $this->failOpenApi(
                'Response body could not be parsed as JSON: ' . $e->getMessage()
                . ($contentType === '' ? ' (no Content-Type header was present on the response)' : ''),
            );
        }
    }
}
