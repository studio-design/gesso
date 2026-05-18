<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting;

use Studio\OpenApiContractTesting\Spec\OpenApiPathMatcher;
use Studio\OpenApiContractTesting\Spec\OpenApiSpecLoader;
use Studio\OpenApiContractTesting\Validation\Request\HeaderParameterValidator;
use Studio\OpenApiContractTesting\Validation\Request\ParameterCollector;
use Studio\OpenApiContractTesting\Validation\Request\PathParameterValidator;
use Studio\OpenApiContractTesting\Validation\Request\QueryParameterValidator;
use Studio\OpenApiContractTesting\Validation\Request\RequestBodyValidator;
use Studio\OpenApiContractTesting\Validation\Request\SecurityValidator;
use Studio\OpenApiContractTesting\Validation\Support\PathDiagnosticsFormatter;
use Studio\OpenApiContractTesting\Validation\Support\SchemaValidatorRunner;
use Studio\OpenApiContractTesting\Validation\Support\SpecResponseKeyResolver;
use Studio\OpenApiContractTesting\Validation\Support\StatusCodePatternSet;
use Studio\OpenApiContractTesting\Validation\Support\ValidatorErrorBoundary;

use function array_keys;
use function is_array;
use function sprintf;
use function strtolower;

final class OpenApiRequestValidator
{
    /**
     * Default response-status patterns that downgrade a request validation
     * failure to a Skipped result when the response status is documented in
     * the spec for the matched operation. 422 / 400 are the canonical
     * "documented client error" codes test suites use to verify server-side
     * input validation; sending intentionally-invalid input to assert these
     * codes is the workflow that the request validator must not double-fail.
     *
     * Empty array disables the downgrade (strict request validation).
     */
    public const DEFAULT_SKIP_REQUEST_VALIDATION_RESPONSE_CODES = ['422', '400'];

    /** @var array<string, OpenApiPathMatcher> */
    private array $pathMatchers = [];
    private readonly PathParameterValidator $pathValidator;
    private readonly QueryParameterValidator $queryValidator;
    private readonly HeaderParameterValidator $headerValidator;
    private readonly SecurityValidator $securityValidator;
    private readonly RequestBodyValidator $bodyValidator;
    private readonly StatusCodePatternSet $skipPatterns;

    /**
     * @param string[] $skipRequestValidationResponseCodes Regex patterns
     *                                                     (without delimiters or anchors) matched against the response status
     *                                                     code as a string. When the response status matches one of these
     *                                                     patterns AND the spec documents that status for the operation,
     *                                                     a request validation failure is downgraded to Skipped instead
     *                                                     of Failure — the test stops false-failing on intentional
     *                                                     invalid-input cases. The downgrade does NOT apply when the
     *                                                     status is undocumented (the spec gap stays loud) nor when
     *                                                     the request was valid (Success stays Success).
     *                                                     Defaults to `[]` here so direct callers stay strict; the
     *                                                     Laravel trait reads the documented `['422', '400']` default
     *                                                     from {@see self::DEFAULT_SKIP_REQUEST_VALIDATION_RESPONSE_CODES}.
     */
    public function __construct(
        int $maxErrors = 20,
        array $skipRequestValidationResponseCodes = [],
    ) {
        $runner = new SchemaValidatorRunner($maxErrors);

        $this->pathValidator = new PathParameterValidator($runner);
        $this->queryValidator = new QueryParameterValidator($runner);
        $this->headerValidator = new HeaderParameterValidator($runner);
        $this->securityValidator = new SecurityValidator();
        $this->bodyValidator = new RequestBodyValidator($runner);
        $this->skipPatterns = new StatusCodePatternSet(
            $skipRequestValidationResponseCodes,
            'skipRequestValidationResponseCodes',
        );
    }

    /**
     * Validate an incoming request against the OpenAPI spec.
     *
     * Composes path-parameter, query-parameter, header-parameter, security,
     * and request-body validation plus any spec-level errors surfaced while
     * collecting merged parameters, and returns a single result. Errors from
     * all sources are accumulated so a single test run surfaces every
     * contract drift the request exhibits.
     *
     * When `$responseStatusCode` is supplied AND validation produced errors
     * AND that status matches a configured `skipRequestValidationResponseCodes`
     * pattern AND the spec documents that status for the matched operation,
     * the result is downgraded from Failure to Skipped. This is the
     * documented-4xx escape hatch from issue #179 that lets dataProvider tests
     * sending intentionally-invalid input keep verifying 4xx behaviour
     * without per-call `withoutRequestValidation()` opt-outs.
     *
     * @param array<string, mixed> $queryParams parsed query string (string|array<string> per key)
     * @param array<array-key, mixed> $headers request headers (string|array<string> per key, case-insensitive name match; non-string keys are silently dropped)
     * @param array<string, mixed> $cookies request cookies (string values per key). Used for apiKey security schemes with `in: cookie`. Caller is expected to pass framework-parsed cookies (e.g. Laravel's `$request->cookies->all()`) — this validator does not parse a `Cookie` header.
     * @param mixed $requestBody the decoded request body. Accepts either a
     *                           {@see DecodedBody} envelope (what the framework
     *                           adapters pass) or a bare decoded value for
     *                           backward compatibility. A bare `null` is read
     *                           as an absent body; a caller that needs to
     *                           assert a literal JSON `null` body must pass
     *                           `DecodedBody::present(null)` explicitly.
     * @param null|int $responseStatusCode optional response status the request produced; enables the documented-4xx downgrade when set
     */
    public function validate(
        string $specName,
        string $method,
        string $requestPath,
        array $queryParams,
        array $headers,
        mixed $requestBody,
        ?string $contentType = null,
        array $cookies = [],
        ?int $responseStatusCode = null,
    ): OpenApiValidationResult {
        // The `mixed` body parameter is kept for backward compatibility.
        // Framework adapters now pass a DecodedBody envelope directly; legacy
        // direct callers pass a bare value, which fromLegacy() normalizes
        // (a plain `null` becomes an absent body — see {@see DecodedBody}).
        $body = DecodedBody::fromLegacy($requestBody);

        $spec = OpenApiSpecLoader::load($specName);

        $version = OpenApiVersion::fromSpec($spec);

        /** @var string[] $specPaths */
        $specPaths = array_keys($spec['paths'] ?? []);
        $matcher = $this->getPathMatcher($specName, $specPaths);
        $matched = $matcher->matchWithVariables($requestPath);

        if ($matched === null) {
            return OpenApiValidationResult::failure([
                PathDiagnosticsFormatter::pathNotFound($specName, $method, $requestPath, $matcher, $spec),
            ]);
        }

        $matchedPath = $matched['path'];
        $pathVariables = $matched['variables'];

        $lowerMethod = strtolower($method);
        /** @var array<string, mixed> $pathSpec */
        $pathSpec = $spec['paths'][$matchedPath] ?? [];

        if (!isset($pathSpec[$lowerMethod])) {
            return OpenApiValidationResult::failure([
                PathDiagnosticsFormatter::methodNotDefined($specName, $method, $matchedPath, $spec),
            ], $matchedPath);
        }

        /** @var array<string, mixed> $operation */
        $operation = $pathSpec[$lowerMethod];

        // Collect merged path/operation parameters once so path + query + header
        // validation share a single view of the spec and malformed-entry errors
        // are surfaced only once.
        $collected = ParameterCollector::collect($method, $matchedPath, $pathSpec, $operation);

        // Each sub-validator is wrapped in ValidatorErrorBoundary::safely() so a
        // RuntimeException thrown from one (typically an opis/json-schema
        // SchemaException via body validation — e.g. InvalidKeywordException from a
        // malformed `pattern` keyword, or UnresolvedReferenceException from a $ref
        // the loader couldn't resolve) is converted to an error string instead of
        // aborting the orchestrator and discarding errors already collected from
        // sibling validators. \LogicException and \Error still bubble so programmer
        // bugs are not silently downgraded to "just another contract error".
        //
        // The boundary is per-sub-validator and permissive: a capture at one stage
        // does NOT short-circuit later stages — every sub-validator still runs so
        // a single test run surfaces as much contract drift as possible.
        $errors = [
            ...$collected->specErrors,
            ...ValidatorErrorBoundary::safely('path', $specName, $method, $matchedPath, fn(): array => $this->pathValidator->validate($method, $matchedPath, $collected->parameters, $pathVariables, $version)),
            ...ValidatorErrorBoundary::safely('query', $specName, $method, $matchedPath, fn(): array => $this->queryValidator->validate($method, $matchedPath, $collected->parameters, $queryParams, $version)),
            ...ValidatorErrorBoundary::safely('header', $specName, $method, $matchedPath, fn(): array => $this->headerValidator->validate($method, $matchedPath, $collected->parameters, $headers, $version)),
            ...ValidatorErrorBoundary::safely('security', $specName, $method, $matchedPath, fn(): array => $this->securityValidator->validate($method, $matchedPath, $spec, $operation, $headers, $queryParams, $cookies)),
            ...ValidatorErrorBoundary::safely('request-body', $specName, $method, $matchedPath, fn(): array => $this->bodyValidator->validate($specName, $method, $matchedPath, $operation, $body, $contentType, $version)),
        ];

        if ($errors === []) {
            return OpenApiValidationResult::success($matchedPath);
        }

        // Issue #179: when the response is a documented 4xx and the test
        // intentionally sent invalid input to verify that status, downgrade
        // the request validation failure to Skipped so the test stops
        // false-failing. Gates on:
        //   1. the caller passed a response status (request hook does this;
        //      direct callers default to null and stay strict);
        //   2. the configured skip-pattern set is non-empty;
        //   3. that status matches a configured pattern;
        //   4. the spec documents that status for THIS operation (exact
        //      / range / default fallback). Undocumented statuses keep the
        //      failure loud — that's a real spec gap and must surface.
        if ($responseStatusCode !== null && !$this->skipPatterns->isEmpty()) {
            $statusCodeStr = (string) $responseStatusCode;
            $matchingPattern = $this->skipPatterns->match($statusCodeStr);
            if ($matchingPattern !== null) {
                /** @var array<string, mixed> $responses */
                $responses = is_array($operation['responses'] ?? null) ? $operation['responses'] : [];
                $matchedResponseKey = SpecResponseKeyResolver::resolve($statusCodeStr, $responses);
                if ($matchedResponseKey !== null) {
                    // Emit the suspicious-keys diagnostic when we
                    // consumed a `default` fallback. Mirrors the
                    // response-side path so a test class with only
                    // auto_validate_request enabled (no auto_assert)
                    // still surfaces spec-key typos.
                    if ($matchedResponseKey === 'default') {
                        SpecResponseKeyResolver::warnSuspiciousKeys($specName, $method, $matchedPath, $responses);
                    }

                    return OpenApiValidationResult::skipped(
                        $matchedPath,
                        sprintf(
                            'request validation skipped: response %s is documented (spec key %s) and matched pattern %s',
                            $statusCodeStr,
                            $matchedResponseKey,
                            $matchingPattern,
                        ),
                        $matchedResponseKey,
                    );
                }
            }
        }

        return OpenApiValidationResult::failure($errors, $matchedPath);
    }

    /**
     * @param string[] $specPaths
     */
    private function getPathMatcher(string $specName, array $specPaths): OpenApiPathMatcher
    {
        return $this->pathMatchers[$specName] ??= new OpenApiPathMatcher($specPaths, OpenApiSpecLoader::getStripPrefixes());
    }
}
