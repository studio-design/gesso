<?php

declare(strict_types=1);

namespace Studio\Gesso\Validation\Response;

use LogicException;
use Studio\Gesso\OpenApiResponseValidator;
use Studio\Gesso\OpenApiVersion;
use Studio\Gesso\Spec\OpenApiOperationResolver;
use Studio\Gesso\Spec\OpenApiPathMatcher;
use Studio\Gesso\Spec\OpenApiSchemaDialect;
use Studio\Gesso\Spec\OpenApiSpecLoader;
use Studio\Gesso\Validation\Support\ContentTypeMatcher;
use Studio\Gesso\Validation\Support\DiscriminatorContext;
use Studio\Gesso\Validation\Support\DiscriminatorEnforcement;
use Studio\Gesso\Validation\Support\MalformedSpecNode;
use Studio\Gesso\Validation\Support\PathDiagnosticsFormatter;
use Studio\Gesso\Validation\Support\SpecResponseKeyResolver;

use function array_key_exists;
use function array_keys;
use function implode;
use function sprintf;

/**
 * Resolves the response schema selected by `(method, path, status, content
 * type)` in a named spec: path matching, operation lookup, status-key
 * resolution (exact → range → `default`), content-type negotiation, and
 * schema conversion with the selected dialect and discriminator enforcement.
 *
 * This is the single implementation behind both the response validator and
 * schema-driven consumers that need a response schema outside an assertion
 * flow (the response-payload explorer, #441). Alternate entry points MUST
 * route through it rather than re-inline the pipeline — that is the
 * invariant that keeps discriminator enforcement, dialect selection, and
 * doctor/runtime malformed-node diagnostics from drifting apart (issue #442).
 *
 * The two-stage API ({@see resolveOperation()} then
 * {@see resolveResponseSchema()}) exists for
 * {@see OpenApiResponseValidator}, whose skip-by-status-code policy applies
 * after the operation's `responses` map is structurally verified but before
 * status-key resolution. Callers without an interleaved policy use
 * {@see resolve()}.
 *
 * Failure diagnostics are formatted here, verbatim to what the validator
 * historically produced, so both consumers surface identical messages.
 *
 * @internal Not part of the package's public API. Do not use from user code.
 */
final class ResponseSchemaResolver
{
    /** @var array<string, OpenApiPathMatcher> */
    private array $pathMatchers = [];

    /**
     * Stage 1: load the spec and resolve the operation's `responses` map.
     *
     * Spec-loading failures (unknown name, undecodable document) throw the
     * loader's usual exceptions; everything below that surfaces as a
     * structured outcome.
     */
    public function resolveOperation(string $specName, string $method, string $requestPath): ResponseOperationResolution
    {
        $spec = OpenApiSpecLoader::load($specName);

        $version = OpenApiVersion::fromSpec($spec);
        $jsonSchemaDialect = OpenApiSchemaDialect::fromSpec($spec, $version);

        // The root `paths` must decode to a JSON object; a scalar, `null`, or
        // a JSON list is a malformed spec ({@see MalformedSpecNode}).
        // Unguarded, a non-array reaches the `array_keys()` call below
        // (uncaught TypeError) and a list mis-resolves silently. The presence
        // test uses `array_key_exists` (not `isset`) so a present-but-`null`
        // `paths` is caught here rather than coalesced to an empty map by
        // `?? []` (issue #259).
        if (array_key_exists('paths', $spec) && MalformedSpecNode::isMalformed($spec['paths'])) {
            return ResponseOperationResolution::malformedSpec($specName, $method, sprintf(
                "Malformed 'paths' for %s %s in '%s' spec: expected object, got %s.",
                $method,
                $requestPath,
                $specName,
                MalformedSpecNode::describe($spec['paths']),
            ));
        }

        /** @var string[] $specPaths */
        $specPaths = array_keys($spec['paths'] ?? []);
        $matcher = $this->pathMatchers[$specName] ??= new OpenApiPathMatcher($specPaths, OpenApiSpecLoader::getStripPrefixes());
        $matchedPath = $matcher->match($requestPath);

        if ($matchedPath === null) {
            return ResponseOperationResolution::pathNotFound(
                $specName,
                $method,
                PathDiagnosticsFormatter::pathNotFound($specName, $method, $requestPath, $matcher, $spec),
            );
        }

        // `$matchedPath` is always a key of `$spec['paths']` (the matcher was
        // built from its `array_keys()`), so `?? null` here only fires for an
        // explicit `null` *value* — which the guard below then treats as
        // malformed, exactly like a scalar path item (issue #259).
        $pathSpec = $spec['paths'][$matchedPath] ?? null;

        if (MalformedSpecNode::isMalformed($pathSpec)) {
            return ResponseOperationResolution::malformedSpec($specName, $method, sprintf(
                "Malformed 'paths[\"%s\"]' for %s %s in '%s' spec: expected object, got %s.",
                $matchedPath,
                $method,
                $matchedPath,
                $specName,
                MalformedSpecNode::describe($pathSpec),
            ), $matchedPath);
        }

        $resolvedOperation = OpenApiOperationResolver::resolve($pathSpec, $method);
        if (!$resolvedOperation['found']) {
            return ResponseOperationResolution::methodNotDefined(
                $specName,
                $method,
                PathDiagnosticsFormatter::methodNotDefined($specName, $method, $matchedPath, $spec),
                $matchedPath,
            );
        }

        $operation = $resolvedOperation['operation'];
        $operationLocation = $resolvedOperation['location'];

        if (MalformedSpecNode::isMalformed($operation)) {
            return ResponseOperationResolution::malformedSpec($specName, $method, sprintf(
                "Malformed 'paths[\"%s\"].%s' for %s %s in '%s' spec: expected object, got %s.",
                $matchedPath,
                $operationLocation,
                $method,
                $matchedPath,
                $specName,
                MalformedSpecNode::describe($operation),
            ), $matchedPath);
        }

        /** @var array<string, mixed> $operation */
        // `array_key_exists` (not `?? []`) so a present-but-`null` `responses`
        // is caught by the guard below as malformed, while a genuinely absent
        // `responses` key still falls back to an empty map (resolved later as
        // "status code not defined").
        $responses = array_key_exists('responses', $operation) ? $operation['responses'] : [];

        if (MalformedSpecNode::isMalformed($responses)) {
            return ResponseOperationResolution::malformedSpec($specName, $method, sprintf(
                "Malformed 'paths[\"%s\"].%s.responses' for %s %s in '%s' spec: expected object, got %s.",
                $matchedPath,
                $operationLocation,
                $method,
                $matchedPath,
                $specName,
                MalformedSpecNode::describe($responses),
            ), $matchedPath);
        }

        /** @var array<array-key, mixed> $responses */
        return ResponseOperationResolution::resolved(
            $specName,
            $method,
            $matchedPath,
            $spec,
            $version,
            $jsonSchemaDialect,
            $responses,
        );
    }

    /**
     * Stage 2: resolve the response entry for the wire status, negotiate the
     * media type against the optional actual response Content-Type, and hand
     * back the selected raw schema with lazy conversion.
     *
     * `$responseContentType` may carry parameters (`; charset=utf-8`) and any
     * casing — it is normalized before matching, exactly like the validator's
     * body path always did.
     */
    public function resolveResponseSchema(
        ResponseOperationResolution $operation,
        int $statusCode,
        ?string $responseContentType = null,
    ): ResponseSchemaResolution {
        if ($operation->outcome !== ResponseSchemaResolutionOutcome::Resolved ||
            $operation->matchedPath === null ||
            $operation->spec === null ||
            $operation->version === null ||
            $operation->jsonSchemaDialect === null ||
            $operation->responses === null
        ) {
            throw new LogicException(sprintf(
                'resolveResponseSchema() requires a Resolved operation resolution; got %s.',
                $operation->outcome->name,
            ));
        }

        $specName = $operation->specName;
        $method = $operation->method;
        $matchedPath = $operation->matchedPath;
        $responses = $operation->responses;
        $statusCodeStr = (string) $statusCode;

        // Spec lookup priority per OpenAPI 3.0/3.1: exact code, then range
        // key (`5XX`), then `default`. Without this fallback, a spec that
        // documents only `default` (or only `5XX`) would fail every real
        // status — both patterns are common (Problem Details responses
        // typically use `default` for the error envelope).
        $matchedResponseKey = SpecResponseKeyResolver::resolve($statusCodeStr, $responses);
        if ($matchedResponseKey === null) {
            return ResponseSchemaResolution::statusNotDeclared(
                $matchedPath,
                "Status code {$statusCode} not defined for {$method} {$matchedPath} in '{$specName}' spec.",
            );
        }

        // Before silently surfacing a `default` fallback, surface any keys
        // that LOOK like attempted spec keys but don't satisfy the exact /
        // range / default form. The wire status is never the literal
        // `default`, so landing on `default` always means a real fallback.
        // Fired here — not in the validator — so every consumer of the
        // shared resolution sees the same typo diagnostic.
        if ($matchedResponseKey === 'default') {
            SpecResponseKeyResolver::warnSuspiciousKeys($specName, $method, $matchedPath, $responses);
        }

        $responseSpec = $responses[$matchedResponseKey];

        // A response entry must decode to a JSON object; a scalar, `null`, or
        // a JSON list is a malformed spec — e.g. an unresolved $ref. The
        // message keys off the matched spec key, not the wire status, so it
        // points at a map entry the spec author actually wrote (issue #258).
        if (MalformedSpecNode::isMalformed($responseSpec)) {
            return ResponseSchemaResolution::malformedResponse($matchedPath, $matchedResponseKey, sprintf(
                "Malformed 'responses[%s]' for %s %s in '%s' spec: expected object, got %s.",
                $matchedResponseKey,
                $method,
                $matchedPath,
                $specName,
                MalformedSpecNode::describe($responseSpec),
            ));
        }

        /** @var array<string, mixed> $responseSpec */
        // 204 No Content (and similar) declare no `content` block. `isset`
        // (not `array_key_exists`) is the historical validator behavior: an
        // explicit `content: null` also lands here.
        if (!isset($responseSpec['content'])) {
            return ResponseSchemaResolution::noContent($matchedPath, $matchedResponseKey, $responseSpec);
        }

        // A `content` block must decode to a JSON object; a scalar or a JSON
        // list is a malformed spec — e.g. an unresolved $ref (issue #256).
        // Content-level malformed diagnostics key off the wire status (the
        // caller's request context), matching the historical validator
        // messages.
        if (MalformedSpecNode::isMalformed($responseSpec['content'])) {
            return ResponseSchemaResolution::malformedContent($matchedPath, $matchedResponseKey, $responseSpec, sprintf(
                "Malformed 'responses[%s].content' for %s %s in '%s' spec: expected object, got %s.",
                $statusCode,
                $method,
                $matchedPath,
                $specName,
                MalformedSpecNode::describe($responseSpec['content']),
            ));
        }

        /** @var array<string, mixed> $content */
        $content = $responseSpec['content'];

        // Pre-scan the content map for malformed media-type entries before
        // any content negotiation runs: a media-type entry and its `schema` /
        // `itemSchema` must each decode to a JSON object — a scalar entry
        // would slip past the downstream `isset(...['schema'])` checks as a
        // silent pass, a non-array `schema` would reach the converter and
        // raise a confusing TypeError (issue #256). `array_key_exists` rather
        // than `isset` so an explicit `schema: null` is also flagged.
        foreach ($content as $mediaType => $mediaTypeSpec) {
            if (MalformedSpecNode::isMalformed($mediaTypeSpec)) {
                return ResponseSchemaResolution::malformedContent($matchedPath, $matchedResponseKey, $responseSpec, sprintf(
                    "Malformed 'responses[%s].content[\"%s\"]' for %s %s in '%s' spec: expected object, got %s.",
                    $statusCode,
                    $mediaType,
                    $method,
                    $matchedPath,
                    $specName,
                    MalformedSpecNode::describe($mediaTypeSpec),
                ));
            }

            if (array_key_exists('schema', $mediaTypeSpec) && MalformedSpecNode::isMalformed($mediaTypeSpec['schema'])) {
                return ResponseSchemaResolution::malformedContent($matchedPath, $matchedResponseKey, $responseSpec, sprintf(
                    "Malformed 'responses[%s].content[\"%s\"].schema' for %s %s in '%s' spec: expected object, got %s.",
                    $statusCode,
                    $mediaType,
                    $method,
                    $matchedPath,
                    $specName,
                    MalformedSpecNode::describe($mediaTypeSpec['schema']),
                ));
            }

            if (array_key_exists('itemSchema', $mediaTypeSpec) && MalformedSpecNode::isMalformed($mediaTypeSpec['itemSchema'])) {
                return ResponseSchemaResolution::malformedContent($matchedPath, $matchedResponseKey, $responseSpec, sprintf(
                    "Malformed 'responses[%s].content[\"%s\"].itemSchema' for %s %s in '%s' spec: expected object, got %s.",
                    $statusCode,
                    $mediaType,
                    $method,
                    $matchedPath,
                    $specName,
                    MalformedSpecNode::describe($mediaTypeSpec['itemSchema']),
                ));
            }
        }

        // When the actual response Content-Type is provided, handle content
        // negotiation: non-JSON types are checked for spec presence only,
        // while JSON-compatible types fall through to schema selection. For
        // JSON-flavoured Content-Types we prefer the spec key that exactly
        // matches before falling back to the first JSON key — this lets
        // multi-JSON specs (e.g. `application/json` +
        // `application/problem+json` for the same status) resolve each
        // Content-Type to its own schema.
        $jsonContentType = null;
        if ($responseContentType !== null) {
            $normalizedType = ContentTypeMatcher::normalizeMediaType($responseContentType);

            if (!ContentTypeMatcher::isJsonContentType($normalizedType)) {
                $matchedKey = ContentTypeMatcher::findContentTypeKey($normalizedType, $content);
                if ($matchedKey !== null) {
                    if (isset($content[$matchedKey]['itemSchema'])) {
                        return ResponseSchemaResolution::itemSchemaStreaming(
                            $matchedPath,
                            $matchedResponseKey,
                            $matchedKey,
                            $responseSpec,
                            self::itemSchemaSkipReason($normalizedType),
                        );
                    }

                    // A matched non-JSON media type that declares a `schema`
                    // is an unvalidatable contract: OpenAPI permits a schema
                    // on any media type, but this engine only evaluates JSON
                    // Schema (issue #254). A non-JSON entry with no `schema`
                    // has nothing to resolve — `isset` is deliberate: an
                    // explicit `schema: null` was already rejected loudly by
                    // the per-media-type guard above.
                    if (isset($content[$matchedKey]['schema'])) {
                        return ResponseSchemaResolution::nonJsonSchema(
                            $matchedPath,
                            $matchedResponseKey,
                            $matchedKey,
                            $responseSpec,
                            sprintf(
                                "response Content-Type '%s' matched non-JSON spec media type '%s', "
                                . 'which declares a schema this validator cannot evaluate (JSON Schema engine only)',
                                $normalizedType,
                                $matchedKey,
                            ),
                        );
                    }

                    return ResponseSchemaResolution::missingSchema($matchedPath, $matchedResponseKey, $matchedKey, $responseSpec);
                }

                $defined = implode(', ', array_keys($content));

                return ResponseSchemaResolution::contentTypeNotDeclared(
                    $matchedPath,
                    $matchedResponseKey,
                    $responseSpec,
                    "Response Content-Type '{$normalizedType}' is not defined for {$method} {$matchedPath} "
                    . "(status {$statusCode}) in '{$specName}' spec. Defined content types: {$defined}",
                );
            }

            $jsonContentType = ContentTypeMatcher::findJsonContentTypeForResponse($normalizedType, $content);
        }

        if ($jsonContentType === null) {
            $jsonContentType = ContentTypeMatcher::findJsonContentType($content);
        }

        // No JSON-compatible content type is defined. A declared `itemSchema`
        // stream stays a loud dedicated outcome; everything else is the
        // "nothing this JSON-Schema engine can select" case.
        if ($jsonContentType === null) {
            foreach ($content as $mediaType => $mediaTypeSpec) {
                if (isset($mediaTypeSpec['itemSchema'])) {
                    return ResponseSchemaResolution::itemSchemaStreaming(
                        $matchedPath,
                        $matchedResponseKey,
                        (string) $mediaType,
                        $responseSpec,
                        self::itemSchemaSkipReason((string) $mediaType),
                    );
                }
            }

            return ResponseSchemaResolution::noJsonContent($matchedPath, $matchedResponseKey, $responseSpec);
        }

        if (!isset($content[$jsonContentType]['schema'])) {
            if (isset($content[$jsonContentType]['itemSchema'])) {
                return ResponseSchemaResolution::itemSchemaStreaming(
                    $matchedPath,
                    $matchedResponseKey,
                    $jsonContentType,
                    $responseSpec,
                    self::itemSchemaSkipReason($jsonContentType),
                );
            }

            return ResponseSchemaResolution::missingSchema($matchedPath, $matchedResponseKey, $jsonContentType, $responseSpec);
        }

        /** @var array<string, mixed> $schema */
        $schema = $content[$jsonContentType]['schema'];

        // Carry the resolved root + enforce gate so conversion can lower
        // `discriminator.mapping` into enforceable conditionals (#262). The
        // mapping pointers reference subtype schemas elsewhere in the
        // document, which only the root can resolve.
        $discriminatorContext = new DiscriminatorContext($operation->spec, DiscriminatorEnforcement::isEnabled());

        return ResponseSchemaResolution::resolved(
            $matchedPath,
            $matchedResponseKey,
            $jsonContentType,
            $responseSpec,
            $schema,
            $operation->version,
            $operation->jsonSchemaDialect,
            $discriminatorContext,
        );
    }

    /**
     * Composed resolution for callers without an interleaved policy between
     * operation lookup and status resolution.
     */
    public function resolve(
        string $specName,
        string $method,
        string $requestPath,
        int $statusCode,
        ?string $responseContentType = null,
    ): ResponseSchemaResolution {
        $operation = $this->resolveOperation($specName, $method, $requestPath);
        if ($operation->outcome !== ResponseSchemaResolutionOutcome::Resolved) {
            return ResponseSchemaResolution::fromOperationFailure($operation);
        }

        return $this->resolveResponseSchema($operation, $statusCode, $responseContentType);
    }

    private static function itemSchemaSkipReason(string $actualMediaType): string
    {
        return sprintf(
            "response Content-Type '%s' uses OpenAPI 3.2 itemSchema streaming semantics; "
            . 'stream items cannot be validated from the buffered response body and were explicitly skipped',
            $actualMediaType,
        );
    }
}
