<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting;

use InvalidArgumentException;
use RuntimeException;
use Studio\OpenApiContractTesting\PHPUnit\OpenApiCoverageExtension;
use Studio\OpenApiContractTesting\Spec\OpenApiPathMatcher;
use Studio\OpenApiContractTesting\Spec\OpenApiSpecLoader;
use Studio\OpenApiContractTesting\Validation\Response\ResponseBodyValidationResult;
use Studio\OpenApiContractTesting\Validation\Response\ResponseBodyValidator;
use Studio\OpenApiContractTesting\Validation\Response\ResponseHeaderValidator;
use Studio\OpenApiContractTesting\Validation\Strict\StrictRequiredBodyWalker;
use Studio\OpenApiContractTesting\Validation\Strict\StrictRequiredPerCallChecker;
use Studio\OpenApiContractTesting\Validation\Strict\StrictRequiredTracker;
use Studio\OpenApiContractTesting\Validation\Support\MalformedSpecNode;
use Studio\OpenApiContractTesting\Validation\Support\PathDiagnosticsFormatter;
use Studio\OpenApiContractTesting\Validation\Support\SchemaValidatorRunner;
use Studio\OpenApiContractTesting\Validation\Support\SpecResponseKeyResolver;
use Studio\OpenApiContractTesting\Validation\Support\StatusCodePatternSet;
use Studio\OpenApiContractTesting\Validation\Support\ValidatorErrorBoundary;

use function array_key_exists;
use function array_keys;
use function array_merge;
use function get_debug_type;
use function is_array;
use function sprintf;
use function strtolower;
use function strtoupper;

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
    private readonly ?StrictRequiredTracker $strictRequiredTracker;

    /**
     * @param string[] $skipResponseCodes Regex patterns (without delimiters or
     *                                    anchors) matched against the response status code as a string. A hit
     *                                    short-circuits validation and returns an `OpenApiValidationResult::skipped()`
     *                                    — isValid() stays true, isSkipped() becomes true, and the matched
     *                                    path is still reported so coverage is recorded.
     * @param null|StrictRequiredTracker $strictRequiredTracker Optional injected tracker (Issue #229). When
     *                                                          omitted, recording falls back to
     *                                                          {@see StrictRequiredTracker::current()}, which
     *                                                          the PHPUnit extension installs at bootstrap.
     *                                                          The Laravel `ValidatesOpenApiSchema` trait
     *                                                          caches one validator per config without
     *                                                          injecting anything, so the fallback is the
     *                                                          common production path; tests or framework
     *                                                          adapters can pass an instance directly to
     *                                                          assert against without touching the
     *                                                          process-global locator.
     */
    public function __construct(
        int $maxErrors = 20,
        array $skipResponseCodes = self::DEFAULT_SKIP_RESPONSE_CODES,
        ?StrictRequiredTracker $strictRequiredTracker = null,
    ) {
        $this->skipPatterns = new StatusCodePatternSet($skipResponseCodes, 'skipResponseCodes');
        $runner = new SchemaValidatorRunner($maxErrors);
        $this->bodyValidator = new ResponseBodyValidator($runner);
        $this->headerValidator = new ResponseHeaderValidator($runner);
        $this->strictRequiredTracker = $strictRequiredTracker;
    }

    /**
     * @param mixed $responseBody the decoded response body. Accepts either a
     *                            {@see DecodedBody} envelope (what the framework
     *                            adapters pass) or a bare decoded value for
     *                            backward compatibility. A bare `null` is read
     *                            as an absent body; a caller that needs to
     *                            assert a literal JSON `null` body must pass
     *                            `DecodedBody::present(null)` explicitly.
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
        // The `mixed` body parameter is kept for backward compatibility.
        // Framework adapters now pass a DecodedBody envelope directly; legacy
        // direct callers pass a bare value, which fromLegacy() normalizes
        // (a plain `null` becomes an absent body — see {@see DecodedBody}).
        $body = DecodedBody::fromLegacy($responseBody);

        $spec = OpenApiSpecLoader::load($specName);

        $version = OpenApiVersion::fromSpec($spec);

        // The root `paths` must decode to a JSON object; a scalar, `null`, or
        // a JSON list is a malformed spec ({@see MalformedSpecNode}).
        // Unguarded, a non-array reaches the `array_keys()` call below
        // (uncaught TypeError) and a list mis-resolves silently. The presence
        // test uses `array_key_exists` (not `isset`) so a present-but-`null`
        // `paths` is caught here rather than coalesced to an empty map by
        // `?? []`. Surface it as a loud spec error instead — the
        // traversal-level sibling of the per-response content/schema guards
        // (issue #259).
        if (array_key_exists('paths', $spec) && MalformedSpecNode::isMalformed($spec['paths'])) {
            return OpenApiValidationResult::failure([
                sprintf(
                    "Malformed 'paths' for %s %s in '%s' spec: expected object, got %s.",
                    $method,
                    $requestPath,
                    $specName,
                    MalformedSpecNode::describe($spec['paths']),
                ),
            ]);
        }

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
        // `$matchedPath` is always a key of `$spec['paths']` (the matcher was
        // built from its `array_keys()`), so `?? null` here only fires for an
        // explicit `null` *value* — which the guard below then treats as
        // malformed, exactly like a scalar path item.
        $pathSpec = $spec['paths'][$matchedPath] ?? null;

        // A path item must decode to a JSON object; a scalar, `null`, or a
        // JSON list is malformed ({@see MalformedSpecNode}). Unguarded, a
        // non-array reaches the `array_key_exists()` method lookup below
        // (uncaught TypeError) and a list mis-resolves silently. Surface it
        // loudly instead (issue #259).
        if (MalformedSpecNode::isMalformed($pathSpec)) {
            return OpenApiValidationResult::failure([
                sprintf(
                    "Malformed 'paths[\"%s\"]' for %s %s in '%s' spec: expected object, got %s.",
                    $matchedPath,
                    $method,
                    $matchedPath,
                    $specName,
                    MalformedSpecNode::describe($pathSpec),
                ),
            ], $matchedPath);
        }

        // `array_key_exists` (not `isset`) so an explicit `{method}: null`
        // reaches the operation guard below as malformed rather than being
        // misreported as an undefined method.
        if (!array_key_exists($lowerMethod, $pathSpec)) {
            return OpenApiValidationResult::failure([
                PathDiagnosticsFormatter::methodNotDefined($specName, $method, $matchedPath, $spec),
            ], $matchedPath);
        }

        $operation = $pathSpec[$lowerMethod];

        // An operation must decode to a JSON object; a scalar, `null`, or a
        // JSON list is malformed ({@see MalformedSpecNode}). Unguarded, a
        // non-array reaches the `array_key_exists()` `responses` lookup below
        // (uncaught TypeError) and a list mis-resolves silently (issue #259).
        if (MalformedSpecNode::isMalformed($operation)) {
            return OpenApiValidationResult::failure([
                sprintf(
                    "Malformed 'paths[\"%s\"].%s' for %s %s in '%s' spec: expected object, got %s.",
                    $matchedPath,
                    $lowerMethod,
                    $method,
                    $matchedPath,
                    $specName,
                    MalformedSpecNode::describe($operation),
                ),
            ], $matchedPath);
        }

        /** @var array<string, mixed> $operation */
        $statusCodeStr = (string) $statusCode;
        // `array_key_exists` (not `?? []`) so a present-but-`null` `responses`
        // is caught by the guard below as malformed, while a genuinely absent
        // `responses` key still falls back to an empty map (resolved later as
        // "status code not defined").
        $responses = array_key_exists('responses', $operation) ? $operation['responses'] : [];

        // The `responses` map must decode to a JSON object; a scalar, `null`,
        // or a JSON list is malformed ({@see MalformedSpecNode}). Unguarded,
        // a non-array reaches `SpecResponseKeyResolver::resolve()`'s `array
        // $responses` parameter (uncaught TypeError) and a list mis-resolves
        // silently. The guard runs BEFORE the skip-by-status-code check
        // below: a malformed `responses` map is a structural spec error, not
        // a status-code-level failure mode, so a configured skip pattern must
        // not hide it. This is the traversal-level sibling of the #258
        // `responses[$status]` per-entry guard (issue #259).
        if (MalformedSpecNode::isMalformed($responses)) {
            return OpenApiValidationResult::failure([
                sprintf(
                    "Malformed 'paths[\"%s\"].%s.responses' for %s %s in '%s' spec: expected object, got %s.",
                    $matchedPath,
                    $lowerMethod,
                    $method,
                    $matchedPath,
                    $specName,
                    MalformedSpecNode::describe($responses),
                ),
            ], $matchedPath);
        }

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

        // A response entry must decode to a JSON object; a scalar, `null`, or
        // a JSON list is a malformed spec ({@see MalformedSpecNode}) — e.g.
        // an unresolved $ref. Surface it as a loud spec error (issue #258).
        // Without this guard the bad value reaches the `array $responseSpec`
        // parameters of validateBody() / validateHeaders() and raises an
        // uncaught TypeError (TypeError extends Error, not RuntimeException,
        // so validateBody()'s catch would not see it). This mirrors the
        // content-level guards in validateBody() and RequestBodyValidator's
        // `requestBody` guard.
        if (MalformedSpecNode::isMalformed($responseSpec)) {
            return OpenApiValidationResult::failure([
                sprintf(
                    "Malformed 'responses[%s]' for %s %s in '%s' spec: expected object, got %s.",
                    $matchedResponseKey,
                    $method,
                    $matchedPath,
                    $specName,
                    MalformedSpecNode::describe($responseSpec),
                ),
            ], $matchedPath, $statusCodeStr);
        }

        /** @var array<string, mixed> $responseSpec */
        $bodyResult = $this->validateBody(
            $specName,
            $method,
            $matchedPath,
            $statusCode,
            $responseSpec,
            $body,
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

        // The body validator matched a non-JSON media-type key that declares
        // a `schema` this JSON-Schema engine cannot evaluate (issue #254).
        // The body was not checked, so surface a Skipped result rather than
        // a clean Success — but only when headers also passed; a real header
        // failure must still fail loudly (it falls through to the error
        // merge below). matchedContentType is forwarded so coverage records
        // the skip against that exact media-type row.
        if ($bodyResult->skipReason !== null && $headerErrors === []) {
            return OpenApiValidationResult::skipped(
                $matchedPath,
                $bodyResult->skipReason,
                $statusCodeStr,
                $bodyResult->matchedContentType,
            );
        }

        // The body validator returns `errors: []` + `matchedContentType: null`
        // (and `skipReason: null`, so the branch above did not fire) for two
        // distinct cases:
        // (a) 204-style — spec has no `content` block; nothing to validate,
        //     legitimately Success.
        // (b) Spec declares only non-JSON content types (e.g. `text/plain`)
        //     with no `schema` and no actual response Content-Type was
        //     supplied to look one up; the result is "we didn't actually
        //     check anything". Without this branch the orchestrator would
        //     mark the response as a clean Success and coverage would credit
        //     the spec's declared content-type as validated even though no
        //     validation occurred. (A non-JSON type that DID match a key
        //     declaring a `schema` is handled by the skipReason branch above
        //     and never reaches here.)
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
            // Strict-required recording happens on the validated success path
            // so that conformance-failing or skipped responses do not
            // contribute to the "field appeared in every response" intersection.
            // The tracker is a no-op when the extension parameter
            // `strict_required` is off (record is still called but consumes
            // negligible memory until the asserter runs).
            $this->maybeRecordStrictRequired(
                $specName,
                $method,
                $matchedPath,
                $statusCodeStr,
                $bodyResult->matchedContentType,
                // The strict-required walker observes the decoded body value;
                // an absent body carries `null` (issues #246 / #248).
                $body->value,
            );

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
     * Feed the strict-required tracker one observation, and (when per-call
     * mode is enabled) emit an immediate `E_USER_WARNING` for any
     * already-drifting pointer.
     *
     * The body is walked once via {@see StrictRequiredBodyWalker::collectPointers()}
     * and the resulting `pointer => list<string>` map is shared with both
     * the run-level tracker (intersection mode, asserts at
     * `ExecutionFinished`) and the per-call checker (Issue #228, fires
     * immediately). Walking once keeps the cost flat regardless of how many
     * gates the user enabled.
     *
     * Only invoked on the Success path (caller guarantees `$errors === []`).
     * Conformance-failing bodies are filtered out by the caller; skipped
     * statuses (matched skip pattern) short-circuit far earlier in
     * `validate()` and never reach this method, so neither gate sees them.
     *
     * Body-shape handling is delegated to the walker (see its docblock for
     * the full matrix). The validator only short-circuits when the walker
     * yields an empty map — strictly: when no object node is observed
     * anywhere in the body (null / scalar root, or arrays containing no
     * object element at any nesting depth).
     *
     * Tracker-side malformed-map guard: if the tracker rejects the pointer
     * map (`InvalidArgumentException` from `record()`), we suppress the
     * per-call checker too rather than letting it iterate the same bad
     * data. The per-call checker has no input-shape validation of its own
     * — `findCoveringDisjunction()` would TypeError on a non-string key,
     * `array_diff()` would silently produce a corrupted "missing" list on
     * a non-list value. Failing the user's test with either is the wrong
     * fingerprint when the underlying bug is in the walker. The single
     * LIBRARY BUG line covers both gates by name so the reader knows
     * neither contributed to drift detection for this observation.
     */
    private function maybeRecordStrictRequired(
        string $specName,
        string $method,
        string $matchedPath,
        string $statusKey,
        ?string $matchedContentType,
        mixed $responseBody,
    ): void {
        $pointers = StrictRequiredBodyWalker::collectPointers($responseBody);
        if ($pointers === []) {
            return;
        }
        $contentTypeKey = $matchedContentType ?? StrictRequiredTracker::ANY_CONTENT_TYPE;
        $tracker = $this->strictRequiredTracker ?? StrictRequiredTracker::current();

        try {
            $tracker->recordOn(
                $specName,
                $method,
                $matchedPath,
                $statusKey,
                $contentTypeKey,
                $pointers,
            );
        } catch (InvalidArgumentException $e) {
            // The walker's contract is "every value is a list of strings,
            // every key is a non-empty pointer string." A throw here means
            // the walker produced something malformed — a library bug, not
            // a user-test failure. Emit a one-shot stderr WARNING with a
            // clear library-bug prefix naming both gates, then return so
            // the per-call checker is not handed the same malformed map
            // (it has no input-shape validation; iterating would TypeError
            // or emit a corrupted warning misattributing the fault to the
            // user). The rest of the test continues normally.
            $message = sprintf(
                '[OpenAPI Strict Required] LIBRARY BUG: walker produced malformed pointer map for %s %s %s; '
                . 'strict_required and strict_required_per_call recording skipped for this observation. '
                . 'Please report at https://github.com/studio-design/openapi-contract-testing/issues '
                . "with the cause: %s\n",
                strtoupper($method),
                $matchedPath,
                $statusKey,
                $e->getMessage(),
            );
            // Routed through the extension's writer rather than `fwrite`
            // so test seams that override stderr capture the LIBRARY BUG
            // line, and so paratest workers' diagnostics travel through
            // the same channel as every other extension stderr line.
            // `OpenApiCoverageExtension::writeStderr()` falls back to bare
            // STDERR when no override is set, so the validator stays usable
            // outside the PHPUnit extension context.
            OpenApiCoverageExtension::writeStderr($message);

            return;
        }

        // Per-call mode (Issue #228) reads the same pointer map the
        // tracker just accepted. The checker short-circuits when its mode
        // is Off (the default for users who only opted into the run-level
        // gate), so unconditional invocation here is the cheapest path.
        StrictRequiredPerCallChecker::maybeWarn(
            $specName,
            $method,
            $matchedPath,
            $statusKey,
            $contentTypeKey,
            $pointers,
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
        DecodedBody $responseBody,
        ?string $responseContentType,
        OpenApiVersion $version,
    ): ResponseBodyValidationResult {
        // 204 No Content (and similar) declare no `content` block. Nothing
        // to validate — return empty so the result aggregates cleanly.
        if (!isset($responseSpec['content'])) {
            return new ResponseBodyValidationResult([], null);
        }

        // A `content` block must decode to a JSON object; a scalar or a JSON
        // list is a malformed spec ({@see MalformedSpecNode}) — e.g. an
        // unresolved $ref. Surface it before it reaches
        // ResponseBodyValidator::validate()'s `array $content` parameter,
        // where a non-array would raise an uncaught TypeError (TypeError
        // extends Error, not RuntimeException, so the catch below would not
        // see it). Mirrors RequestBodyValidator's `requestBody.content` guard
        // (issue #256).
        if (MalformedSpecNode::isMalformed($responseSpec['content'])) {
            return new ResponseBodyValidationResult([
                sprintf(
                    "Malformed 'responses[%s].content' for %s %s in '%s' spec: expected object, got %s.",
                    $statusCode,
                    $method,
                    $matchedPath,
                    $specName,
                    MalformedSpecNode::describe($responseSpec['content']),
                ),
            ], null);
        }

        /** @var array<string, mixed> $content */
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
