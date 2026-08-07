<?php

declare(strict_types=1);

namespace Studio\Gesso;

use InvalidArgumentException;
use LogicException;
use RuntimeException;
use Studio\Gesso\Coverage\OpenApiCoverageTracker;
use Studio\Gesso\PHPUnit\OpenApiCoverageExtension;
use Studio\Gesso\Spec\OpenApiOperationResolver;
use Studio\Gesso\Validation\Response\ResponseBodyValidationResult;
use Studio\Gesso\Validation\Response\ResponseBodyValidator;
use Studio\Gesso\Validation\Response\ResponseHeaderValidator;
use Studio\Gesso\Validation\Response\ResponseSchemaResolution;
use Studio\Gesso\Validation\Response\ResponseSchemaResolutionOutcome;
use Studio\Gesso\Validation\Response\ResponseSchemaResolver;
use Studio\Gesso\Validation\Strict\StrictAdditionalPropertiesInspector;
use Studio\Gesso\Validation\Strict\StrictAdditionalPropertiesPerCallChecker;
use Studio\Gesso\Validation\Strict\StrictAdditionalPropertiesTracker;
use Studio\Gesso\Validation\Strict\StrictRequiredBodyWalker;
use Studio\Gesso\Validation\Strict\StrictRequiredPerCallChecker;
use Studio\Gesso\Validation\Strict\StrictRequiredTracker;
use Studio\Gesso\Validation\Support\NamedError;
use Studio\Gesso\Validation\Support\SchemaValidatorRunner;
use Studio\Gesso\Validation\Support\StatusCodePatternSet;
use Studio\Gesso\Validation\Support\ValidationPolicyDefaults;
use Studio\Gesso\Validation\Support\ValidatorErrorBoundary;

use function array_map;
use function array_merge;
use function array_values;
use function count;
use function get_debug_type;
use function is_array;
use function sprintf;
use function strtoupper;

final class OpenApiResponseValidator
{
    /**
     * Regex patterns (without delimiters or anchors) that match response status
     * codes which should skip body validation. The default of `5\d\d` reflects
     * the common convention of not documenting production 5xx in specs.
     */
    public const DEFAULT_SKIP_RESPONSE_CODES = ['5\d\d'];
    private readonly ResponseSchemaResolver $schemaResolver;
    private readonly ResponseBodyValidator $bodyValidator;
    private readonly ResponseHeaderValidator $headerValidator;
    private readonly StatusCodePatternSet $skipPatterns;
    private readonly StrictAdditionalPropertiesTracker $strictAdditionalPropertiesTracker;
    private readonly StrictRequiredTracker $strictRequiredTracker;

    /**
     * @param null|int $maxErrors Maximum number of reported errors (0 =
     *                            unlimited). `null` — the default — reads the process-wide value set
     *                            by the PHPUnit extension's `max_errors` parameter (issue #502),
     *                            which falls back to 20 when unconfigured.
     * @param null|string[] $skipResponseCodes Regex patterns (without delimiters or
     *                                         anchors) matched against the response status code as a string. A hit
     *                                         short-circuits validation and returns an `OpenApiValidationResult::skipped()`
     *                                         — isValid() stays true, isSkipped() becomes true, and the matched
     *                                         path is still reported so coverage is recorded.
     *                                         `null` — the default — reads the process-wide value set by the
     *                                         extension's `skip_response_codes` parameter, which falls back to
     *                                         {@see self::DEFAULT_SKIP_RESPONSE_CODES} when unconfigured.
     * @param StrictRequiredTracker $strictRequiredTracker Tracker receiving successful response-body
     *                                                     observations. Framework adapters resolve their
     *                                                     run-level tracker at the integration boundary;
     *                                                     direct callers must choose the tracker explicitly.
     */
    public function __construct(
        StrictRequiredTracker $strictRequiredTracker,
        ?int $maxErrors = null,
        ?array $skipResponseCodes = null,
    ) {
        $this->skipPatterns = new StatusCodePatternSet(
            $skipResponseCodes ?? ValidationPolicyDefaults::skipResponseCodes(),
            'skipResponseCodes',
        );
        $this->schemaResolver = new ResponseSchemaResolver();
        $runner = new SchemaValidatorRunner($maxErrors ?? ValidationPolicyDefaults::maxErrors());
        $this->bodyValidator = new ResponseBodyValidator($runner);
        $this->headerValidator = new ResponseHeaderValidator($runner);
        $this->strictRequiredTracker = $strictRequiredTracker;
        $this->strictAdditionalPropertiesTracker = StrictAdditionalPropertiesTracker::current();
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
        $result = $this->validateWithoutRecording(
            $specName,
            $method,
            $requestPath,
            $statusCode,
            $responseBody,
            $responseContentType,
            $responseHeaders,
        );

        // Issue #535: the validator records its own coverage observation, so
        // the framework-independent path feeds the coverage table without a
        // manual recordResponse() call. One exchange gets exactly one
        // recording site: adapters that post-process results before returning
        // them (PSR-7) call validateWithoutRecording() and record their final
        // result themselves; everything else records here.
        // Recording follows the result, not the outcome: any result whose
        // path matched the spec is an observation, including failures
        // (schemaValidated means "the body was actually checked", which a
        // schema-violating response still satisfies). The literal wire status
        // stands in when status resolution never produced a spec key.
        $matchedPath = $result->matchedPath();
        if ($matchedPath !== null) {
            OpenApiCoverageTracker::recordResponse(
                $specName,
                $method,
                $matchedPath,
                $result->matchedStatusCode() ?? (string) $statusCode,
                $result->matchedContentType(),
                schemaValidated: !$result->isSkipped(),
                skipReason: $result->skipReason(),
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
     * @param mixed $responseBody see {@see self::validate()}
     * @param null|array<array-key, mixed> $responseHeaders see {@see self::validate()}
     */
    public function validateWithoutRecording(
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

        // Spec traversal — the `paths` guard, path matching, operation
        // lookup, and the `responses`-map guard — is shared with every other
        // response-schema consumer through {@see ResponseSchemaResolver}
        // (issue #442). The resolver formats the failure diagnostics; this
        // validator only maps each outcome onto its historical result shape.
        $operationResolution = $this->schemaResolver->resolveOperation($specName, $method, $requestPath);

        if ($operationResolution->outcome !== ResponseSchemaResolutionOutcome::Resolved) {
            $message = (string) $operationResolution->message;
            // Structural spec defects and request-context mismatches keep
            // their historical issue categories: malformed nodes are spec
            // errors, an unmatched path/method is a request-context error.
            $category = $operationResolution->outcome === ResponseSchemaResolutionOutcome::MalformedSpec
                ? 'response.spec'
                : 'response.request_context';

            return OpenApiValidationResult::failure(
                [$message],
                $operationResolution->matchedPath,
                issues: [new ValidationIssue($category, $message, method: $method, path: $operationResolution->matchedPath)],
            );
        }

        $matchedPath = $operationResolution->matchedPath;
        $version = $operationResolution->version;
        $jsonSchemaDialect = $operationResolution->jsonSchemaDialect;
        if ($matchedPath === null || $version === null || $jsonSchemaDialect === null) {
            throw new LogicException('Resolved operation resolution must carry matchedPath, version, and dialect.');
        }

        $statusCodeStr = (string) $statusCode;

        // Skip-by-status-code: applied after the resolver's structural
        // guards (a malformed `responses` map is a spec error a skip pattern
        // must not hide — issue #259) but before status-key resolution, so a
        // configured skip suppresses both status-code-level failure modes —
        // "this code isn't in the spec's responses map" AND "this code IS
        // documented but the body doesn't match its schema". Earlier checks
        // (path / method not in spec) still fail loudly so typos stay
        // visible. This interleaved policy is why the validator consumes the
        // resolver's staged API rather than its composed resolve().
        $matchingPattern = $this->skipPatterns->match($statusCodeStr);
        if ($matchingPattern !== null) {
            // matchedStatusCode here is the literal HTTP status string, not a
            // spec key. Skip happens BEFORE key resolution, so we don't yet
            // know which spec key would have matched — and even when the
            // spec only declares `default` or a `5XX` range, callers that
            // gate on isSkipped() expect the wire status, not the resolved
            // spec key. The coverage tracker's statusKeyMatches() reconciles
            // literal-vs-range at compute time.
            return OpenApiValidationResult::skipped(
                $matchedPath,
                sprintf('status %s matched skip pattern %s', $statusCodeStr, $matchingPattern),
                $statusCodeStr,
            );
        }

        $resolution = $this->schemaResolver->resolveResponseSchema($operationResolution, $statusCode, $responseContentType);

        if ($resolution->outcome === ResponseSchemaResolutionOutcome::StatusNotDeclared) {
            $message = (string) $resolution->message;

            return OpenApiValidationResult::failure(
                [$message],
                $matchedPath,
                issues: [new ValidationIssue('response.status', $message, method: $method, path: $matchedPath, statusCode: $statusCodeStr)],
            );
        }

        $statusKey = $resolution->statusKey;
        if ($statusKey === null) {
            throw new LogicException('Response schema resolution past status lookup must carry the matched status key.');
        }

        // Coverage tracking records under the spec key actually matched
        // (e.g. "5XX" or "default"), not the literal status — that lets
        // the renderer surface the spec's intent rather than the wire value.
        $statusCodeStr = $statusKey;

        if ($resolution->outcome === ResponseSchemaResolutionOutcome::MalformedResponse) {
            $message = (string) $resolution->message;

            return OpenApiValidationResult::failure(
                [$message],
                $matchedPath,
                $statusCodeStr,
                issues: [new ValidationIssue('response.spec', $message, method: $method, path: $matchedPath, statusCode: $statusCodeStr)],
            );
        }

        $responseSpec = $resolution->responseSpec;
        if ($responseSpec === null) {
            throw new LogicException('Response schema resolution past the entry guard must carry the response spec node.');
        }

        $bodyResult = $this->validateBody(
            $specName,
            $method,
            $matchedPath,
            $statusCode,
            $resolution,
            $body,
        );

        $headerErrors = $this->validateHeaders(
            $specName,
            $method,
            $matchedPath,
            $responseSpec,
            $responseHeaders,
            $version,
            $jsonSchemaDialect,
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

        // Order is body errors first, headers second, and the
        // undeclared-Content-Type note (issue #435) last of all — it explains
        // the whole failure, so it must not be buried between the errors it
        // annotates and a header error that has nothing to do with it. Tests
        // that pin specific positions rely on this; reordering would silently
        // change diagnostic flow without breaking behaviour. Category tags
        // mirror the producing sub-validator (#282, stage 1).
        $issues = [];
        // The violation list mirrors the body errors index-for-index only on
        // the schema-error path; non-schema body errors (empty body, decode
        // failures) ship an empty list, so gate on the counts before pairing.
        $aligned = $bodyResult->violations !== [] && count($bodyResult->violations) === count($bodyResult->errors);
        foreach (array_values($bodyResult->errors) as $index => $message) {
            $issues[] = new ValidationIssue(
                'response.body',
                $message,
                instancePath: $aligned ? $bodyResult->violations[$index]->instancePath : null,
                keyword: $aligned ? $bodyResult->violations[$index]->keyword : null,
                method: $method,
                path: $matchedPath,
                statusCode: $statusCodeStr,
                contentType: $bodyResult->matchedContentType,
            );
        }
        foreach ($headerErrors as $headerError) {
            // No contentType: header validation is independent of the response
            // media type, and the documented contract reserves that context
            // field for body issues. The header name rides along as
            // `parameter` so baseline fingerprints can tell two header
            // violations on one operation apart (#402).
            $issues[] = new ValidationIssue('response.header', $headerError->message, instancePath: $headerError->instancePath, keyword: $headerError->keyword, method: $method, path: $matchedPath, statusCode: $statusCodeStr, parameter: $headerError->name);
        }
        // The response arrived as a JSON media type the spec does not declare
        // for this status, so the body was checked against the first JSON key
        // instead (issue #435). Alone, the resulting mismatch reads as "the
        // body is wrong"; the note names the undeclared media type as a
        // candidate cause. It rides along only when the body actually failed —
        // the fallback itself stays a pass, which is the documented behaviour.
        $contentTypeNote = $bodyResult->errors !== [] ? $resolution->contentTypeNote : null;
        if ($contentTypeNote !== null) {
            $issues[] = new ValidationIssue(
                'response.content_type',
                $contentTypeNote,
                method: $method,
                path: $matchedPath,
                statusCode: $statusCodeStr,
                contentType: $bodyResult->matchedContentType,
            );
        }
        $errors = array_merge(
            $bodyResult->errors,
            array_map(static fn(NamedError $headerError): string => $headerError->message, $headerErrors),
            $contentTypeNote !== null ? [$contentTypeNote] : [],
        );

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
            $this->maybeRecordStrictAdditionalProperties(
                $responseSpec,
                $specName,
                $method,
                $matchedPath,
                $statusCodeStr,
                $bodyResult->matchedContentType,
                $body->value,
                $version,
                $jsonSchemaDialect,
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
            issues: $issues,
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

        try {
            $this->strictRequiredTracker->recordOn(
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
                . 'Please report at https://github.com/studio-design/gesso/issues '
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
    private function maybeRecordStrictAdditionalProperties(
        array $responseSpec,
        string $specName,
        string $method,
        string $matchedPath,
        string $statusKey,
        ?string $matchedContentType,
        mixed $responseBody,
        OpenApiVersion $version,
        string $jsonSchemaDialect,
    ): void {
        $contentTypeKey = $matchedContentType ?? StrictAdditionalPropertiesTracker::ANY_CONTENT_TYPE;
        $schema = $matchedContentType === null
            ? null
            : ($responseSpec['content'][$matchedContentType]['schema'] ?? null);
        if (!is_array($schema)) {
            return;
        }

        $findings = StrictAdditionalPropertiesInspector::inspect(
            $responseBody,
            $schema,
            jsonSchemaDialect: $jsonSchemaDialect,
            honorSchemaDialectOverride: $version !== OpenApiVersion::V3_0,
        );

        try {
            $this->strictAdditionalPropertiesTracker->recordOn(
                $specName,
                $method,
                $matchedPath,
                $statusKey,
                $contentTypeKey,
                $findings,
            );
        } catch (InvalidArgumentException $e) {
            OpenApiCoverageExtension::writeStderr(sprintf(
                '[OpenAPI Strict Additional Properties] LIBRARY BUG: malformed findings for %s %s %s; '
                . "recording skipped. Cause: %s\n",
                strtoupper($method),
                $matchedPath,
                $statusKey,
                $e->getMessage(),
            ));

            return;
        }

        StrictAdditionalPropertiesPerCallChecker::maybeWarn(
            OpenApiOperationResolver::normalizeMethodForKey($method),
            $matchedPath,
            $statusKey,
            $contentTypeKey,
            $findings,
        );
    }

    /**
     * Map a post-entry response-schema resolution onto the historical body
     * result shape. Content negotiation, media-type guards, and schema
     * selection already ran in {@see ResponseSchemaResolver}; only the
     * `Resolved` outcome still validates the actual body. Pre-body outcomes
     * (operation failures, `StatusNotDeclared`, `MalformedResponse`) are
     * handled by {@see validate()} before this method and throw here.
     */
    private function validateBody(
        string $specName,
        string $method,
        string $matchedPath,
        int $statusCode,
        ResponseSchemaResolution $resolution,
        DecodedBody $responseBody,
    ): ResponseBodyValidationResult {
        return match ($resolution->outcome) {
            // 204-style responses without a `content` block, and content
            // blocks with no JSON-compatible media type: nothing this engine
            // can validate — return empty so the result aggregates cleanly
            // (the orchestrator decides between Success and the non-JSON
            // skip using the spec's content block).
            ResponseSchemaResolutionOutcome::NoContent,
            ResponseSchemaResolutionOutcome::NoJsonContent => new ResponseBodyValidationResult([], null),
            // Malformed content-level spec nodes and an actual Content-Type
            // the spec never declared surface as loud body errors.
            ResponseSchemaResolutionOutcome::MalformedContent,
            ResponseSchemaResolutionOutcome::ContentTypeNotDeclared => new ResponseBodyValidationResult(
                [(string) $resolution->message],
                null,
            ),
            // A matched media type without a `schema` has nothing to
            // validate; record the matched key for coverage.
            ResponseSchemaResolutionOutcome::MissingSchema => new ResponseBodyValidationResult(
                [],
                $resolution->contentType,
            ),
            // Deliberately unvalidated bodies (non-JSON schema, OAS 3.2
            // itemSchema streaming) carry the skip reason so the
            // orchestrator surfaces a Skipped result, not a clean pass.
            ResponseSchemaResolutionOutcome::NonJsonSchema,
            ResponseSchemaResolutionOutcome::ItemSchemaStreaming => new ResponseBodyValidationResult(
                [],
                $resolution->contentType,
                $resolution->skipReason,
            ),
            ResponseSchemaResolutionOutcome::Resolved => $this->validateResolvedBody(
                $specName,
                $method,
                $matchedPath,
                $statusCode,
                $resolution,
                $responseBody,
            ),
            default => throw new LogicException(sprintf(
                'validateBody() received a pre-body resolution outcome %s; validate() must handle it first.',
                $resolution->outcome->name,
            )),
        };
    }

    private function validateResolvedBody(
        string $specName,
        string $method,
        string $matchedPath,
        int $statusCode,
        ResponseSchemaResolution $resolution,
        DecodedBody $responseBody,
    ): ResponseBodyValidationResult {
        // Inlined try/catch mirrors ValidatorErrorBoundary::safely() for the
        // body validator: same narrow `RuntimeException` catch, same error
        // formatting. The boundary returns string[]; the body validator
        // returns a richer DTO carrying matchedContentType, so we can't reuse
        // the helper as-is. Schema conversion happens lazily inside this
        // boundary ({@see ResponseSchemaResolution::convertedSchema()}), so
        // converter rejections keep their historical error shape.
        // \LogicException and \Error still bubble.
        try {
            return $this->bodyValidator->validate(
                $specName,
                $method,
                $matchedPath,
                $statusCode,
                $resolution,
                $responseBody,
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
     * @return list<NamedError>
     */
    private function validateHeaders(
        string $specName,
        string $method,
        string $matchedPath,
        array $responseSpec,
        ?array $responseHeaders,
        OpenApiVersion $version,
        string $jsonSchemaDialect,
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
            return [new NamedError(null, sprintf(
                "[response-header] spec 'headers' must be an object for %s %s; got %s.",
                $method,
                $matchedPath,
                get_debug_type($headersSpec),
            ))];
        }

        if ($headersSpec === []) {
            return [];
        }

        /** @var array<string, mixed> $headersSpec */
        return ValidatorErrorBoundary::safelyNamed(
            'response-header',
            $specName,
            $method,
            $matchedPath,
            fn(): array => $this->headerValidator->validate($headersSpec, $responseHeaders, $version, $jsonSchemaDialect),
        );
    }
}
