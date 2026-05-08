<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting;

use RuntimeException;
use Studio\OpenApiContractTesting\Spec\OpenApiPathMatcher;
use Studio\OpenApiContractTesting\Spec\OpenApiSpecLoader;
use Studio\OpenApiContractTesting\Validation\Response\ResponseBodyValidationResult;
use Studio\OpenApiContractTesting\Validation\Response\ResponseBodyValidator;
use Studio\OpenApiContractTesting\Validation\Response\ResponseHeaderValidator;
use Studio\OpenApiContractTesting\Validation\Support\PathDiagnosticsFormatter;
use Studio\OpenApiContractTesting\Validation\Support\SchemaValidatorRunner;
use Studio\OpenApiContractTesting\Validation\Support\SpecResponseKeyResolver;
use Studio\OpenApiContractTesting\Validation\Support\StatusCodePatternSet;
use Studio\OpenApiContractTesting\Validation\Support\ValidatorErrorBoundary;

use function array_keys;
use function array_merge;
use function get_debug_type;
use function is_array;
use function sprintf;
use function strtolower;

final class OpenApiResponseValidator
{
    /**
     * Regex patterns (without delimiters or anchors) that match response status
     * codes which should skip body validation. The default of `5\d\d` reflects
     * the common convention of not documenting production 5xx in specs.
     */
    public const DEFAULT_SKIP_RESPONSE_CODES = ['5\d\d'];

    /** @var array<string, OpenApiPathMatcher> */
    private array $pathMatchers = [];
    private readonly ResponseBodyValidator $bodyValidator;
    private readonly ResponseHeaderValidator $headerValidator;
    private readonly StatusCodePatternSet $skipPatterns;

    /**
     * @param string[] $skipResponseCodes Regex patterns (without delimiters or
     *                                    anchors) matched against the response status code as a string. A hit
     *                                    short-circuits validation and returns an `OpenApiValidationResult::skipped()`
     *                                    — isValid() stays true, isSkipped() becomes true, and the matched
     *                                    path is still reported so coverage is recorded.
     */
    public function __construct(
        int $maxErrors = 20,
        array $skipResponseCodes = self::DEFAULT_SKIP_RESPONSE_CODES,
    ) {
        $this->skipPatterns = new StatusCodePatternSet($skipResponseCodes, 'skipResponseCodes');
        $runner = new SchemaValidatorRunner($maxErrors);
        $this->bodyValidator = new ResponseBodyValidator($runner);
        $this->headerValidator = new ResponseHeaderValidator($runner);
    }

    /**
     * @param null|array<array-key, mixed> $responseHeaders the response's actual headers
     *                                                      (as returned by HeaderBag::all() — a map of name to list-of-values
     *                                                      or to a single string). When null, header validation is skipped
     *                                                      entirely; pass `[]` to validate against a spec that requires
     *                                                      headers but the response sent none.
     */
    public function validate(
        string $specName,
        string $method,
        string $requestPath,
        int $statusCode,
        mixed $responseBody,
        ?string $responseContentType = null,
        ?array $responseHeaders = null,
    ): OpenApiValidationResult {
        $spec = OpenApiSpecLoader::load($specName);

        $version = OpenApiVersion::fromSpec($spec);

        /** @var string[] $specPaths */
        $specPaths = array_keys($spec['paths'] ?? []);
        $matcher = $this->getPathMatcher($specName, $specPaths);
        $matchedPath = $matcher->match($requestPath);

        if ($matchedPath === null) {
            return OpenApiValidationResult::failure([
                PathDiagnosticsFormatter::pathNotFound($specName, $method, $requestPath, $matcher, $spec),
            ]);
        }

        $lowerMethod = strtolower($method);
        $pathSpec = $spec['paths'][$matchedPath] ?? [];

        if (!isset($pathSpec[$lowerMethod])) {
            return OpenApiValidationResult::failure([
                PathDiagnosticsFormatter::methodNotDefined($specName, $method, $matchedPath, $spec),
            ], $matchedPath);
        }

        $statusCodeStr = (string) $statusCode;
        $responses = $pathSpec[$lowerMethod]['responses'] ?? [];

        // Skip-by-status-code: applied before the "Status code not defined"
        // branch so a configured skip suppresses both status-code-level failure
        // modes — "this code isn't in the spec's responses map" AND "this code
        // IS documented but the body doesn't match its schema". Earlier checks
        // (path / method not in spec) still fail loudly so typos stay visible.
        $matchingPattern = $this->skipPatterns->match($statusCodeStr);
        if ($matchingPattern !== null) {
            // matchedStatusCode here is the literal HTTP status string, not a
            // spec key. Skip happens BEFORE key resolution
            // ({@see SpecResponseKeyResolver::resolve()} runs further
            // down), so we don't yet know which spec key would have
            // matched — and even when the spec only declares `default`
            // or a `5XX` range, callers that gate on isSkipped() expect
            // the wire status, not the resolved spec key. The coverage
            // tracker's statusKeyMatches() reconciles literal-vs-range
            // at compute time.
            return OpenApiValidationResult::skipped(
                $matchedPath,
                sprintf('status %s matched skip pattern %s', $statusCodeStr, $matchingPattern),
                $statusCodeStr,
            );
        }

        // Spec lookup priority per OpenAPI 3.0/3.1:
        //   1. Exact code match (e.g. spec declares "503", response is 503)
        //   2. Range key match (e.g. spec declares "5XX", response is 503)
        //   3. `default` catch-all
        // Explicit codes take precedence over range keys; range keys take
        // precedence over `default`. Without this fallback, a spec that
        // documents only `default` (or only `5XX`) would fail every real
        // status — both patterns are common (Problem Details responses
        // typically use `default` for the error envelope).
        $matchedResponseKey = SpecResponseKeyResolver::resolve($statusCodeStr, $responses);
        if ($matchedResponseKey === null) {
            return OpenApiValidationResult::failure([
                "Status code {$statusCode} not defined for {$method} {$matchedPath} in '{$specName}' spec.",
            ], $matchedPath);
        }
        // Before silently surfacing a `default` fallback, surface any keys
        // that LOOK like attempted spec keys but don't satisfy the exact /
        // range / default form. `$statusCodeStr` is always a wire status
        // here (numeric string from `(string) $statusCode`), never the
        // literal `default`, so falling-through to `default` always means
        // a real fallback. Both the request-side downgrade
        // ({@see OpenApiRequestValidator::validate()}) and this
        // response-side path call the same helper so a test class with
        // only one hook enabled still sees the diagnostic — duplicate
        // warnings under both hooks are accepted noise.
        if ($matchedResponseKey === 'default') {
            SpecResponseKeyResolver::warnSuspiciousKeys($specName, $method, $matchedPath, $responses);
        }

        // Coverage tracking records under the spec key actually matched
        // (e.g. "5XX" or "default"), not the literal status — that lets
        // the renderer surface the spec's intent rather than the wire value.
        $statusCodeStr = $matchedResponseKey;
        $responseSpec = $responses[$matchedResponseKey];

        $bodyResult = $this->validateBody(
            $specName,
            $method,
            $matchedPath,
            $statusCode,
            $responseSpec,
            $responseBody,
            $responseContentType,
            $version,
        );

        $headerErrors = $this->validateHeaders(
            $specName,
            $method,
            $matchedPath,
            $responseSpec,
            $responseHeaders,
            $version,
        );

        // The body validator returns ([], null) for two distinct cases:
        // (a) 204-style — spec has no `content` block; nothing to validate,
        //     legitimately Success.
        // (b) Spec declares only non-JSON content types (e.g. `text/plain`)
        //     and we have no schema engine for them; the result is "we
        //     didn't actually check anything". Without this branch the
        //     orchestrator would mark the response as a clean Success and
        //     coverage would credit the spec's declared content-type as
        //     validated even though no validation occurred.
        // Distinguishing them requires looking at the spec — `content`
        // present + non-empty + bodyResult.matchedContentType null + body
        // had no errors → case (b).
        $hasContentBlock = isset($responseSpec['content']) && is_array($responseSpec['content']) && $responseSpec['content'] !== [];
        if ($bodyResult->errors === [] && $bodyResult->matchedContentType === null && $hasContentBlock && $headerErrors === []) {
            return OpenApiValidationResult::skipped(
                $matchedPath,
                'spec declares only non-JSON content types and the validator has no schema engine for them',
                $statusCodeStr,
            );
        }

        // Order is body errors first, headers second. Tests that pin
        // specific positions rely on this; reordering would silently
        // change diagnostic flow without breaking behaviour.
        $errors = array_merge($bodyResult->errors, $headerErrors);

        if ($errors === []) {
            return OpenApiValidationResult::success(
                $matchedPath,
                $statusCodeStr,
                $bodyResult->matchedContentType,
            );
        }

        return OpenApiValidationResult::failure(
            $errors,
            $matchedPath,
            $statusCodeStr,
            $bodyResult->matchedContentType,
        );
    }

    /**
     * @param array<string, mixed> $responseSpec
     */
    private function validateBody(
        string $specName,
        string $method,
        string $matchedPath,
        int $statusCode,
        array $responseSpec,
        mixed $responseBody,
        ?string $responseContentType,
        OpenApiVersion $version,
    ): ResponseBodyValidationResult {
        // 204 No Content (and similar) declare no `content` block. Nothing
        // to validate — return empty so the result aggregates cleanly.
        if (!isset($responseSpec['content'])) {
            return new ResponseBodyValidationResult([], null);
        }

        /** @var array<string, array<string, mixed>> $content */
        $content = $responseSpec['content'];

        // Inlined try/catch mirrors ValidatorErrorBoundary::safely() for the
        // body validator: same narrow `RuntimeException` catch, same error
        // formatting. The boundary returns string[]; the body validator now
        // returns a richer DTO carrying matchedContentType, so we can't reuse
        // the helper as-is. \LogicException and \Error still bubble.
        try {
            return $this->bodyValidator->validate(
                $specName,
                $method,
                $matchedPath,
                $statusCode,
                $content,
                $responseBody,
                $responseContentType,
                $version,
            );
        } catch (RuntimeException $e) {
            $previous = $e->getPrevious();
            $previousSuffix = $previous !== null
                ? sprintf(' (caused by %s: %s)', $previous::class, $previous->getMessage())
                : '';

            return new ResponseBodyValidationResult(
                [sprintf(
                    "[%s] %s %s in '%s' spec: %s threw: %s%s",
                    'response-body',
                    $method,
                    $matchedPath,
                    $specName,
                    $e::class,
                    $e->getMessage(),
                    $previousSuffix,
                )],
                null,
            );
        }
    }

    /**
     * @param array<string, mixed> $responseSpec
     * @param null|array<array-key, mixed> $responseHeaders
     *
     * @return string[]
     */
    private function validateHeaders(
        string $specName,
        string $method,
        string $matchedPath,
        array $responseSpec,
        ?array $responseHeaders,
        OpenApiVersion $version,
    ): array {
        // Header validation is opt-in: callers that pre-date the parameter
        // (or framework-agnostic adapters that never see headers) pass null
        // and get the historical body-only behaviour. An explicit empty
        // array means "the response has no headers" and still triggers
        // required-header checks against the spec.
        if ($responseHeaders === null) {
            return [];
        }

        if (!isset($responseSpec['headers'])) {
            return [];
        }

        $headersSpec = $responseSpec['headers'];

        // A `headers` block that decoded to a non-mapping is a malformed
        // spec (e.g. YAML scalar where an object was expected). Surface
        // it as an error so the spec author notices instead of getting
        // a silent pass that hides every header from validation.
        if (!is_array($headersSpec)) {
            return [sprintf(
                "[response-header] spec 'headers' must be an object for %s %s; got %s.",
                $method,
                $matchedPath,
                get_debug_type($headersSpec),
            )];
        }

        if ($headersSpec === []) {
            return [];
        }

        /** @var array<string, mixed> $headersSpec */
        return ValidatorErrorBoundary::safely(
            'response-header',
            $specName,
            $method,
            $matchedPath,
            fn(): array => $this->headerValidator->validate($headersSpec, $responseHeaders, $version),
        );
    }

    /**
     * @param string[] $specPaths
     */
    private function getPathMatcher(string $specName, array $specPaths): OpenApiPathMatcher
    {
        return $this->pathMatchers[$specName] ??= new OpenApiPathMatcher($specPaths, OpenApiSpecLoader::getStripPrefixes());
    }
}
