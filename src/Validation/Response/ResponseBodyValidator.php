<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Validation\Response;

use stdClass;
use Studio\OpenApiContractTesting\DecodedBody;
use Studio\OpenApiContractTesting\OpenApiResponseValidator;
use Studio\OpenApiContractTesting\OpenApiValidationResult;
use Studio\OpenApiContractTesting\OpenApiVersion;
use Studio\OpenApiContractTesting\SchemaContext;
use Studio\OpenApiContractTesting\Spec\OpenApiSchemaConverter;
use Studio\OpenApiContractTesting\Validation\Support\ContentTypeMatcher;
use Studio\OpenApiContractTesting\Validation\Support\MalformedSpecNode;
use Studio\OpenApiContractTesting\Validation\Support\ObjectConverter;
use Studio\OpenApiContractTesting\Validation\Support\SchemaValidatorRunner;

use function array_key_exists;
use function array_keys;
use function implode;
use function in_array;
use function is_array;
use function is_string;
use function sprintf;

/**
 * @internal Not part of the package's public API. Do not use from user code.
 */
final class ResponseBodyValidator
{
    public function __construct(
        private readonly SchemaValidatorRunner $runner,
    ) {}

    /**
     * Validate the response body against the matched operation's response
     * entry. Returns a {@see ResponseBodyValidationResult} with an empty
     * `errors` list when the body is acceptable (non-JSON content types that
     * are present in the spec, JSON media types with no `schema` key, and
     * JSON bodies that pass schema validation). Hard failures (content-type
     * not defined, empty body against a JSON schema, schema mismatch) are
     * returned as error strings so the orchestrator can assemble the final
     * {@see OpenApiValidationResult}.
     *
     * `matchedContentType` is the spec key (with its original casing) the
     * body was checked against, or `null` when no spec lookup occurred —
     * used by coverage tracking to record per-(status, media-type) granularity.
     *
     * The 204-style "no content" case is handled upstream in
     * {@see OpenApiResponseValidator::validate()} — when the response spec
     * has no `content` key, this validator is never invoked.
     *
     * @param array<string, mixed> $content the `responses[$status].content` map.
     *                                      Values are media-type objects, but a malformed spec can
     *                                      carry a scalar — the guard loop below rejects those loudly
     *                                      before any value is dereferenced as an array.
     */
    public function validate(
        string $specName,
        string $method,
        string $matchedPath,
        int $statusCode,
        array $content,
        DecodedBody $responseBody,
        ?string $responseContentType,
        OpenApiVersion $version,
    ): ResponseBodyValidationResult {
        // Pre-scan the content map for malformed media-type entries before any
        // content negotiation runs. This mirrors RequestBodyValidator's
        // per-media-type guards: a media-type entry and its `schema` must each
        // decode to a JSON object — a scalar entry would slip past the
        // downstream `isset(...['schema'])` checks as a silent pass, a non-array
        // `schema` on a JSON media type would reach OpenApiSchemaConverter and
        // raise a confusing TypeError, and a JSON list mis-resolves silently.
        // Surface all of them as loud spec-level errors via
        // {@see MalformedSpecNode} (issue #256). `matchedContentType` is null:
        // no content-type lookup succeeded.
        foreach ($content as $mediaType => $mediaTypeSpec) {
            if (MalformedSpecNode::isMalformed($mediaTypeSpec)) {
                return new ResponseBodyValidationResult([
                    sprintf(
                        "Malformed 'responses[%s].content[\"%s\"]' for %s %s in '%s' spec: expected object, got %s.",
                        $statusCode,
                        $mediaType,
                        $method,
                        $matchedPath,
                        $specName,
                        MalformedSpecNode::describe($mediaTypeSpec),
                    ),
                ], null);
            }

            // array_key_exists rather than isset so an explicit `schema: null`
            // is also flagged — otherwise it falls through the downstream
            // presence check as a silent "no schema" pass.
            if (array_key_exists('schema', $mediaTypeSpec) && MalformedSpecNode::isMalformed($mediaTypeSpec['schema'])) {
                return new ResponseBodyValidationResult([
                    sprintf(
                        "Malformed 'responses[%s].content[\"%s\"].schema' for %s %s in '%s' spec: expected object, got %s.",
                        $statusCode,
                        $mediaType,
                        $method,
                        $matchedPath,
                        $specName,
                        MalformedSpecNode::describe($mediaTypeSpec['schema']),
                    ),
                ], null);
            }
        }

        // When the actual response Content-Type is provided, handle content negotiation:
        // non-JSON types are checked for spec presence only, while JSON-compatible types
        // fall through to schema validation. For JSON-flavoured response Content-Types
        // we prefer the spec key that exactly matches the response Content-Type before
        // falling back to the first JSON key — this lets multi-JSON specs (e.g.
        // `application/json` + `application/problem+json` for the same status) validate
        // each Content-Type against its own schema.
        $jsonContentType = null;
        if ($responseContentType !== null) {
            $normalizedType = ContentTypeMatcher::normalizeMediaType($responseContentType);

            if (!ContentTypeMatcher::isJsonContentType($normalizedType)) {
                // Non-JSON response: check if the content type is defined in the spec.
                $matchedKey = ContentTypeMatcher::findContentTypeKey($normalizedType, $content);
                if ($matchedKey !== null) {
                    // A matched non-JSON media type that declares a `schema`
                    // is an unvalidatable contract: OpenAPI permits a schema
                    // on any media type, but this engine only evaluates JSON
                    // Schema. Surface a skip (issue #254) so the unchecked
                    // body is not recorded as a clean pass. A non-JSON entry
                    // with no `schema` has nothing to validate — stay
                    // silently successful, as before.
                    //
                    // `isset` (not `array_key_exists`) is deliberate: an
                    // explicit `schema: null` is a degenerate entry, and the
                    // per-media-type malformed-schema guard above already
                    // rejected it loudly before this point — so it never
                    // reaches here as a silent "no schema" case.
                    if (isset($content[$matchedKey]['schema'])) {
                        return new ResponseBodyValidationResult(
                            [],
                            $matchedKey,
                            sprintf(
                                "response Content-Type '%s' matched non-JSON spec media type '%s', "
                                . 'which declares a schema this validator cannot evaluate (JSON Schema engine only)',
                                $normalizedType,
                                $matchedKey,
                            ),
                        );
                    }

                    return new ResponseBodyValidationResult([], $matchedKey);
                }

                $defined = implode(', ', array_keys($content));

                return new ResponseBodyValidationResult(
                    [
                        "Response Content-Type '{$normalizedType}' is not defined for {$method} {$matchedPath} (status {$statusCode}) in '{$specName}' spec. Defined content types: {$defined}",
                    ],
                    null,
                );
            }

            // JSON-compatible response: prefer exact match, then fall back to the first JSON key.
            $jsonContentType = ContentTypeMatcher::findJsonContentTypeForResponse($normalizedType, $content);
        }

        if ($jsonContentType === null) {
            $jsonContentType = ContentTypeMatcher::findJsonContentType($content);
        }

        // If no JSON-compatible content type is defined, skip body validation.
        // This validator only handles JSON schemas; non-JSON types (e.g. text/html,
        // application/xml) are outside its scope.
        if ($jsonContentType === null) {
            return new ResponseBodyValidationResult([], null);
        }

        if (!isset($content[$jsonContentType]['schema'])) {
            return new ResponseBodyValidationResult([], $jsonContentType);
        }

        // An absent body fails the contract: this validator only runs once the
        // spec is known to declare a JSON-compatible schema for the response.
        // A literal JSON `null` body is distinct — `$responseBody->present` is
        // true with a `null` value (issues #246 / #248), so it falls through
        // to schema type-checking below instead of taking this branch.
        if (!$responseBody->present) {
            return new ResponseBodyValidationResult(
                [
                    "Response body is empty but {$method} {$matchedPath} (status {$statusCode}) defines a JSON-compatible response schema in '{$specName}' spec.",
                ],
                $jsonContentType,
            );
        }

        $bodyValue = $responseBody->value;

        /** @var array<string, mixed> $schema */
        $schema = $content[$jsonContentType]['schema'];
        $jsonSchema = OpenApiSchemaConverter::convert($schema, $version, SchemaContext::Response);

        // PHP's `json_decode($json, true)` returns `[]` for both `[]` and `{}`.
        // The Laravel trait's response decoder uses associative-array decoding
        // (so callers can treat the body as an array), which means an empty
        // `{}` body lands here as PHP `[]`. ObjectConverter preserves empty
        // arrays as JSON arrays, so the schema's `type: object` would then
        // reject the body with a misleading "must match the type: object"
        // error. Coerce `[]` → stdClass when the schema explicitly accepts
        // an object so the empty-object-against-type-object case (very
        // common for status acks and "no items yet" responses) validates.
        if ($bodyValue === [] && self::schemaAcceptsObject($schema)) {
            $bodyValue = new stdClass();
        }

        $schemaObject = ObjectConverter::convert($jsonSchema);
        $dataObject = ObjectConverter::convert($bodyValue);

        $formatted = $this->runner->validate($schemaObject, $dataObject);

        $errors = [];
        foreach ($formatted as $path => $messages) {
            foreach ($messages as $message) {
                $errors[] = "[{$path}] {$message}";
            }
        }

        return new ResponseBodyValidationResult($errors, $jsonContentType);
    }

    /**
     * Whether the schema's top-level type explicitly accepts a JSON object.
     * Handles OAS 3.0 (`type: object`) and OAS 3.1 (`type: ["object", "null"]`).
     * Composition keywords (`oneOf` / `anyOf` / `allOf`) are intentionally
     * NOT walked — coercion only fires for the unambiguous case so a real
     * type-mismatch error still surfaces for `type: array` schemas where the
     * empty-array body is genuinely wrong. Intentional duplicate of the
     * same-named helper on the request-side body validator; if you change
     * the scope here, change it there too.
     *
     * @param array<string, mixed> $schema
     */
    private static function schemaAcceptsObject(array $schema): bool
    {
        $type = $schema['type'] ?? null;

        if (is_string($type)) {
            return $type === 'object';
        }

        if (is_array($type)) {
            return in_array('object', $type, true);
        }

        return false;
    }
}
