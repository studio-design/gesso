<?php

declare(strict_types=1);

namespace Studio\Gesso;

use RuntimeException;
use Studio\Gesso\Coverage\OpenApiCoverageTracker;
use Studio\Gesso\Spec\OpenApiOperationResolver;
use Studio\Gesso\Spec\OpenApiPathMatcher;
use Studio\Gesso\Spec\OpenApiSchemaDialect;
use Studio\Gesso\Spec\OpenApiSpecLoader;
use Studio\Gesso\Validation\Request\HeaderParameterValidator;
use Studio\Gesso\Validation\Request\ParameterCollector;
use Studio\Gesso\Validation\Request\PathParameterValidator;
use Studio\Gesso\Validation\Request\QueryParameterValidator;
use Studio\Gesso\Validation\Request\RequestBodyValidationResult;
use Studio\Gesso\Validation\Request\RequestBodyValidator;
use Studio\Gesso\Validation\Request\SecurityValidator;
use Studio\Gesso\Validation\Support\ContentTypeMatcher;
use Studio\Gesso\Validation\Support\DiscriminatorContext;
use Studio\Gesso\Validation\Support\DiscriminatorEnforcement;
use Studio\Gesso\Validation\Support\MalformedSpecNode;
use Studio\Gesso\Validation\Support\NamedError;
use Studio\Gesso\Validation\Support\PathDiagnosticsFormatter;
use Studio\Gesso\Validation\Support\SchemaValidatorRunner;
use Studio\Gesso\Validation\Support\SpecResponseKeyResolver;
use Studio\Gesso\Validation\Support\StatusCodePatternSet;
use Studio\Gesso\Validation\Support\ValidatorErrorBoundary;

use function array_key_exists;
use function array_keys;
use function array_map;
use function count;
use function is_array;
use function sprintf;

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
     * @param null|string $rawQueryString the request's percent-encoded query string as sent on the wire (e.g. `role=owner%2Cadmin,member`). Optional: when supplied, non-exploded query styles (`form` + `explode: false`, `pipeDelimited`, `spaceDelimited`) are split before percent-decoding. For `form` this keeps a `%2C` inside a value data; the `pipeDelimited` / `spaceDelimited` delimiters cannot be represented inside a value (OAS Appendix E leaves that undefined) — both their encoded and literal forms split. The raw value is only consulted when it decodes to the parsed value in `$queryParams`. Without it the decoded value is split as a best effort.
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
        ?string $rawQueryString = null,
    ): OpenApiValidationResult {
        $result = $this->validateWithoutRecording(
            $specName,
            $method,
            $requestPath,
            $queryParams,
            $headers,
            $requestBody,
            $contentType,
            $cookies,
            $responseStatusCode,
            $rawQueryString,
        );

        // Issue #535: request coverage is recorded here, mirroring the
        // response side — the framework adapters no longer record it.
        $matchedPath = $result->matchedPath();
        if ($matchedPath !== null) {
            OpenApiCoverageTracker::recordRequest(
                $specName,
                $method,
                $matchedPath,
                $result->isSkipped() ? $result->skipReason() : null,
            );
        }

        return $result;
    }

    /**
     * {@see self::validate()} without the coverage recording, for adapters
     * that post-process the result and record the final version themselves.
     *
     * @internal adapter wiring, not a consumer API
     *
     * @param array<string, mixed> $queryParams see {@see self::validate()}
     * @param array<array-key, mixed> $headers see {@see self::validate()}
     * @param mixed $requestBody see {@see self::validate()}
     * @param array<string, mixed> $cookies see {@see self::validate()}
     */
    public function validateWithoutRecording(
        string $specName,
        string $method,
        string $requestPath,
        array $queryParams,
        array $headers,
        mixed $requestBody,
        ?string $contentType = null,
        array $cookies = [],
        ?int $responseStatusCode = null,
        ?string $rawQueryString = null,
    ): OpenApiValidationResult {
        // The `mixed` body parameter is kept for backward compatibility.
        // Framework adapters now pass a DecodedBody envelope directly; legacy
        // direct callers pass a bare value, which fromLegacy() normalizes
        // (a plain `null` becomes an absent body — see {@see DecodedBody}).
        $body = DecodedBody::fromLegacy($requestBody);

        $spec = OpenApiSpecLoader::load($specName);

        $version = OpenApiVersion::fromSpec($spec);
        $jsonSchemaDialect = OpenApiSchemaDialect::fromSpec($spec, $version);

        // The root `paths` must decode to a JSON object; a scalar, `null`, or
        // a JSON list is a malformed spec ({@see MalformedSpecNode}).
        // Unguarded, a non-array reaches the `array_keys()` call below
        // (uncaught TypeError) and a list mis-resolves silently. The presence
        // test uses `array_key_exists` (not `isset`) so a present-but-`null`
        // `paths` is caught here rather than coalesced to an empty map by
        // `?? []`. Surface it as a loud spec error instead, mirroring the
        // response-side traversal guards (issue #259).
        if (array_key_exists('paths', $spec) && MalformedSpecNode::isMalformed($spec['paths'])) {
            $message = sprintf(
                "Malformed 'paths' for %s %s in '%s' spec: expected object, got %s.",
                $method,
                $requestPath,
                $specName,
                MalformedSpecNode::describe($spec['paths']),
            );

            return OpenApiValidationResult::failure(
                [$message],
                issues: [new ValidationIssue('request.spec', $message, method: $method)],
            );
        }

        /** @var string[] $specPaths */
        $specPaths = array_keys($spec['paths'] ?? []);
        $matcher = $this->getPathMatcher($specName, $specPaths);
        $matched = $matcher->matchWithVariables($requestPath);

        if ($matched === null) {
            $message = PathDiagnosticsFormatter::pathNotFound($specName, $method, $requestPath, $matcher, $spec);

            return OpenApiValidationResult::failure(
                [$message],
                issues: [new ValidationIssue('request.path_match', $message, method: $method)],
            );
        }

        $matchedPath = $matched['path'];
        $pathVariables = $matched['variables'];

        // `$matchedPath` is always a key of `$spec['paths']` (the matcher was
        // built from its `array_keys()`), so `?? null` here only fires for an
        // explicit `null` *value* — which the guard below then treats as
        // malformed, exactly like a scalar path item.
        $pathSpec = $spec['paths'][$matchedPath] ?? null;

        // A path item must decode to a JSON object; a scalar, `null`, or a
        // JSON list is malformed ({@see MalformedSpecNode}). Unguarded, a
        // non-array reaches the `array_key_exists()` method lookup below (and
        // `ParameterCollector::collect()`'s `array $pathSpec` parameter),
        // raising an uncaught TypeError, and a list mis-resolves silently.
        // Surface it loudly instead (issue #259).
        if (MalformedSpecNode::isMalformed($pathSpec)) {
            $message = sprintf(
                "Malformed 'paths[\"%s\"]' for %s %s in '%s' spec: expected object, got %s.",
                $matchedPath,
                $method,
                $matchedPath,
                $specName,
                MalformedSpecNode::describe($pathSpec),
            );

            return OpenApiValidationResult::failure(
                [$message],
                $matchedPath,
                issues: [new ValidationIssue('request.spec', $message, method: $method, path: $matchedPath)],
            );
        }

        /** @var array<string, mixed> $pathSpec */
        $resolvedOperation = OpenApiOperationResolver::resolve($pathSpec, $method);
        if (!$resolvedOperation['found']) {
            $message = PathDiagnosticsFormatter::methodNotDefined($specName, $method, $matchedPath, $spec);

            return OpenApiValidationResult::failure(
                [$message],
                $matchedPath,
                issues: [new ValidationIssue('request.method', $message, method: $method, path: $matchedPath)],
            );
        }

        $operation = $resolvedOperation['operation'];
        $operationLocation = $resolvedOperation['location'];

        // An operation must decode to a JSON object; a scalar, `null`, or a
        // JSON list is malformed ({@see MalformedSpecNode}). A non-array
        // would reach `ParameterCollector::collect()`'s `array $operation`
        // parameter (the first scalar-typed sink) and raise an uncaught
        // TypeError; a list mis-resolves silently (issue #259).
        if (MalformedSpecNode::isMalformed($operation)) {
            $message = sprintf(
                "Malformed 'paths[\"%s\"].%s' for %s %s in '%s' spec: expected object, got %s.",
                $matchedPath,
                $operationLocation,
                $method,
                $matchedPath,
                $specName,
                MalformedSpecNode::describe($operation),
            );

            return OpenApiValidationResult::failure(
                [$message],
                $matchedPath,
                issues: [new ValidationIssue('request.spec', $message, method: $method, path: $matchedPath)],
            );
        }

        // Collect merged path/operation parameters once so path + query + header
        // validation share a single view of the spec and malformed-entry errors
        // are surfaced only once.
        /** @var array<string, mixed> $operation */
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
        // The body validator returns a richer DTO (errors + an optional
        // skipReason) rather than a bare string[], so it cannot flow through
        // ValidatorErrorBoundary::safely() like the other sub-validators.
        // validateBody() runs it behind the same narrow RuntimeException
        // boundary inline — mirrors OpenApiResponseValidator::validateBody().
        // Carry the resolved root + enforce gate so the body validator can
        // lower `discriminator.mapping` into enforceable conditionals (#262).
        $discriminatorContext = new DiscriminatorContext($spec, DiscriminatorEnforcement::isEnabled());
        $bodyResult = $this->validateBody($specName, $method, $matchedPath, $operation, $body, $contentType, $version, $discriminatorContext, $jsonSchemaDialect);

        // Category tags mirror the sub-validator that produced each message so
        // issues() can expose a structured view without touching the
        // sub-validators themselves (#282, stage 1). Only body issues have a
        // resolved spec media-type key and (on the schema-error path) a
        // structured violation twin; parameter/security errors instead carry
        // the name of the parameter / scheme they are about (#402), so
        // baseline fingerprints can tell two violations on one operation
        // apart without relying on message wording.
        $issueGroups = [
            ['request.spec', self::withoutNames($collected->specErrors), null, []],
            ['request.parameter.path', ValidatorErrorBoundary::safelyNamed('path', $specName, $method, $matchedPath, fn(): array => $this->pathValidator->validate($method, $matchedPath, $collected->parameters, $pathVariables, $version, $jsonSchemaDialect)), null, []],
            ['request.parameter.query', ValidatorErrorBoundary::safelyNamed('query', $specName, $method, $matchedPath, fn(): array => $this->queryValidator->validate($method, $matchedPath, $collected->parameters, $queryParams, $version, $jsonSchemaDialect, $rawQueryString)), null, []],
            ['request.parameter.header', ValidatorErrorBoundary::safelyNamed('header', $specName, $method, $matchedPath, fn(): array => $this->headerValidator->validate($method, $matchedPath, $collected->parameters, $headers, $version, $jsonSchemaDialect)), null, []],
            ['request.security', ValidatorErrorBoundary::safelyNamed('security', $specName, $method, $matchedPath, fn(): array => $this->securityValidator->validate($method, $matchedPath, $spec, $operation, $headers, $queryParams, $cookies)), null, []],
            ['request.body', self::withoutNames($bodyResult->errors), $bodyResult->matchedContentType, $bodyResult->violations],
        ];

        $issues = [];
        foreach ($issueGroups as [$category, $namedErrors, $issueContentType, $violations]) {
            // The violation list mirrors the messages index-for-index only on
            // the schema-error path; non-schema body errors ship an empty
            // list, so gate on the counts before pairing the two.
            $aligned = $violations !== [] && count($violations) === count($namedErrors);
            foreach ($namedErrors as $index => $namedError) {
                $issues[] = new ValidationIssue(
                    $category,
                    $namedError->message,
                    instancePath: $aligned ? $violations[$index]->instancePath : $namedError->instancePath,
                    keyword: $aligned ? $violations[$index]->keyword : $namedError->keyword,
                    method: $method,
                    path: $matchedPath,
                    contentType: $issueContentType,
                    parameter: $namedError->name,
                );
            }
        }
        $errors = array_map(static fn(ValidationIssue $issue): string => $issue->message, $issues);

        if ($errors === []) {
            // Issue #254: a non-JSON request Content-Type matched a spec
            // media-type key declaring a `schema` this JSON-Schema engine
            // cannot evaluate. No sibling validator failed, so the request
            // is non-failing — but the body went unchecked, so surface a
            // Skipped result (rather than a clean Success) and forward the
            // reason to coverage tracking.
            // Both outcomes carry the media-type key the body validator
            // resolved (null when no `requestBody` lookup happened) so
            // adapters can tag their own body-level diagnostics with it even
            // when this result contributes no issues of its own.
            if ($bodyResult->skipReason !== null) {
                return OpenApiValidationResult::skipped(
                    $matchedPath,
                    $bodyResult->skipReason,
                    matchedContentType: $bodyResult->matchedContentType,
                );
            }

            return OpenApiValidationResult::success(
                $matchedPath,
                matchedContentType: $bodyResult->matchedContentType,
            );
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

                    // Carry the media-type key the body validator resolved
                    // before the downgrade: the Skipped result has no issues,
                    // so this is the only channel through which adapters
                    // (e.g. PSR-7 body-decode errors) can keep tagging their
                    // request.body issues with the resolved key.
                    return OpenApiValidationResult::skipped(
                        $matchedPath,
                        sprintf(
                            'request validation skipped: response %s is documented (spec key %s) and matched pattern %s',
                            $statusCodeStr,
                            $matchedResponseKey,
                            $matchingPattern,
                        ),
                        $matchedResponseKey,
                        $bodyResult->matchedContentType,
                    );
                }
            }
        }

        // Carry the resolved media-type key at result level here too, so all
        // three outcomes (Success / Skipped above, Failure) expose it
        // consistently — adapters fall back to it when the failure has no
        // request.body issue to borrow the key from (e.g. only sibling
        // parameter errors alongside an adapter-level body error).
        return OpenApiValidationResult::failure(
            $errors,
            $matchedPath,
            matchedContentType: $bodyResult->matchedContentType,
            issues: $issues,
        );
    }

    /**
     * Lift plain message strings into the named-error shape the issue loop
     * consumes, with no name attached — spec-level and body errors are not
     * about a single named parameter (body issues carry `instancePath`
     * instead).
     *
     * @param string[] $messages
     *
     * @return list<NamedError>
     */
    private static function withoutNames(array $messages): array
    {
        $named = [];
        foreach ($messages as $message) {
            $named[] = new NamedError(null, $message);
        }

        return $named;
    }

    /**
     * Media-type key for the synthetic boundary error above. A
     * `RuntimeException` can only originate on the JSON schema path — schema
     * conversion and validation run after {@see RequestBodyValidator} resolved
     * the JSON media-type key (the non-JSON and malformed-spec paths return
     * before touching the converter) — so re-resolving the key from the same
     * `content` map reproduces exactly what the validator matched before it
     * threw.
     *
     * @param array<string, mixed> $operation
     */
    private static function thrownBodyContentType(array $operation): ?string
    {
        $requestBody = $operation['requestBody'] ?? null;
        if (!is_array($requestBody) || !is_array($requestBody['content'] ?? null)) {
            return null;
        }

        /** @var array<string, mixed> $content */
        $content = $requestBody['content'];

        return ContentTypeMatcher::findJsonContentType($content);
    }

    /**
     * Run the request-body validator behind the same narrow
     * `RuntimeException` boundary {@see ValidatorErrorBoundary::safely()}
     * applies to the other sub-validators: a `RuntimeException` (typically
     * an opis/json-schema `SchemaException` raised from schema conversion
     * or validation) is converted to an error string instead of aborting
     * the orchestrator. The body validator returns a
     * {@see RequestBodyValidationResult} DTO carrying an optional
     * `skipReason`, so it cannot reuse the string[]-returning helper as-is
     * — same reasoning as {@see OpenApiResponseValidator::validateBody()}.
     * `\LogicException` and `\Error` still bubble so programmer bugs are
     * not silently downgraded to contract errors.
     *
     * @param array<string, mixed> $operation
     */
    private function validateBody(
        string $specName,
        string $method,
        string $matchedPath,
        array $operation,
        DecodedBody $body,
        ?string $contentType,
        OpenApiVersion $version,
        DiscriminatorContext $discriminatorContext,
        string $jsonSchemaDialect,
    ): RequestBodyValidationResult {
        try {
            return $this->bodyValidator->validate($specName, $method, $matchedPath, $operation, $body, $contentType, $version, $discriminatorContext, $jsonSchemaDialect);
        } catch (RuntimeException $e) {
            $previous = $e->getPrevious();
            $previousSuffix = $previous !== null
                ? sprintf(' (caused by %s: %s)', $previous::class, $previous->getMessage())
                : '';

            return new RequestBodyValidationResult(
                [sprintf(
                    "[%s] %s %s in '%s' spec: %s threw: %s%s",
                    'request-body',
                    $method,
                    $matchedPath,
                    $specName,
                    $e::class,
                    $e->getMessage(),
                    $previousSuffix,
                )],
                matchedContentType: self::thrownBodyContentType($operation),
            );
        }
    }

    /**
     * @param string[] $specPaths
     */
    private function getPathMatcher(string $specName, array $specPaths): OpenApiPathMatcher
    {
        return $this->pathMatchers[$specName] ??= new OpenApiPathMatcher($specPaths, OpenApiSpecLoader::getStripPrefixes());
    }
}
