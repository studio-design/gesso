<?php

declare(strict_types=1);

namespace Studio\Gesso\Validation\Request;

use stdClass;
use Studio\Gesso\DecodedBody;
use Studio\Gesso\OpenApiVersion;
use Studio\Gesso\SchemaContext;
use Studio\Gesso\Spec\OpenApiSchemaConverter;
use Studio\Gesso\UploadedPart;
use Studio\Gesso\Validation\Support\ContentTypeMatcher;
use Studio\Gesso\Validation\Support\DiscriminatorContext;
use Studio\Gesso\Validation\Support\FormBodyDecoder;
use Studio\Gesso\Validation\Support\MalformedSpecNode;
use Studio\Gesso\Validation\Support\ObjectConverter;
use Studio\Gesso\Validation\Support\SchemaValidatorRunner;

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
final class RequestBodyValidator
{
    public function __construct(
        private readonly SchemaValidatorRunner $runner,
    ) {}

    /**
     * Validate the request body against the operation's `requestBody` schema.
     *
     * Returns a {@see RequestBodyValidationResult} with an empty `errors`
     * list when the body is acceptable (including when the spec defines no
     * body, or an optional body has no content, JSON content type, or schema).
     * Hard spec-level errors (malformed `requestBody` / `content`) are reported as error
     * entries so the orchestrator can accumulate them alongside other
     * validators' errors. A non-JSON Content-Type that matched a spec
     * media-type key declaring a `schema` this engine cannot evaluate yields
     * an empty `errors` list plus a non-null `skipReason` (issue #254). Form
     * media types are the exception: their schema is applied to the parsed
     * field map ({@see self::validateFormBody()}, issue #405).
     *
     * @param array<string, mixed> $operation
     * @param null|DiscriminatorContext $discriminatorContext carries the resolved root + enforce gate
     *                                                        for `discriminator.mapping` lowering (Issue
     *                                                        #262). `null` (the default for direct
     *                                                        callers) means no enforcement.
     */
    public function validate(
        string $specName,
        string $method,
        string $matchedPath,
        array $operation,
        DecodedBody $requestBody,
        ?string $contentType,
        OpenApiVersion $version,
        ?DiscriminatorContext $discriminatorContext = null,
        ?string $jsonSchemaDialect = null,
    ): RequestBodyValidationResult {
        // OpenAPI: a missing requestBody means the operation accepts no body — treat as success.
        if (!isset($operation['requestBody'])) {
            return new RequestBodyValidationResult([]);
        }

        // A `requestBody` must decode to a JSON object; a scalar or a JSON
        // list signals a malformed spec ({@see MalformedSpecNode}).
        // Contract-testing tools should surface this, not mask it as "no body".
        if (MalformedSpecNode::isMalformed($operation['requestBody'])) {
            return new RequestBodyValidationResult([
                sprintf(
                    "Malformed 'requestBody' for %s %s in '%s' spec: expected object, got %s.",
                    $method,
                    $matchedPath,
                    $specName,
                    MalformedSpecNode::describe($operation['requestBody']),
                ),
            ]);
        }

        /** @var array<string, mixed> $requestBodySpec */
        $requestBodySpec = $operation['requestBody'];

        $required = ($requestBodySpec['required'] ?? false) === true;

        if (!isset($requestBodySpec['content'])) {
            if ($required && !$requestBody->present) {
                return self::missingRequiredBodyResult($specName, $method, $matchedPath);
            }

            return new RequestBodyValidationResult([]);
        }

        if (MalformedSpecNode::isMalformed($requestBodySpec['content'])) {
            return new RequestBodyValidationResult([
                sprintf(
                    "Malformed 'requestBody.content' for %s %s in '%s' spec: expected object, got %s.",
                    $method,
                    $matchedPath,
                    $specName,
                    MalformedSpecNode::describe($requestBodySpec['content']),
                ),
            ]);
        }

        /** @var array<string, mixed> $content */
        $content = $requestBodySpec['content'];

        foreach ($content as $mediaType => $mediaTypeSpec) {
            // The @var on $content narrows values to array, but PHPDoc is unchecked at
            // runtime — a malformed spec like `content: {"application/json": "oops"}`
            // would TypeError on downstream array accesses. Surface it as a loud spec
            // error instead, matching the sibling guard on `requestBody.content` above.
            // A JSON list written for the entry is rejected the same way
            // ({@see MalformedSpecNode}).
            if (MalformedSpecNode::isMalformed($mediaTypeSpec)) {
                return new RequestBodyValidationResult([
                    sprintf(
                        "Malformed 'requestBody.content[\"%s\"]' for %s %s in '%s' spec: expected object, got %s.",
                        $mediaType,
                        $method,
                        $matchedPath,
                        $specName,
                        MalformedSpecNode::describe($mediaTypeSpec),
                    ),
                ]);
            }

            // `schema: "oops"` (or any other non-array scalar) would slip past the
            // downstream `isset(...['schema'])` presence check and reach
            // OpenApiSchemaConverter::convert() as a scalar, producing a confusing
            // TypeError instead of a spec-level error. array_key_exists rather than
            // isset so an explicit `schema: null` is also flagged.
            if (array_key_exists('schema', $mediaTypeSpec) && MalformedSpecNode::isMalformed($mediaTypeSpec['schema'])) {
                return new RequestBodyValidationResult([
                    sprintf(
                        "Malformed 'requestBody.content[\"%s\"].schema' for %s %s in '%s' spec: expected object, got %s.",
                        $mediaType,
                        $method,
                        $matchedPath,
                        $specName,
                        MalformedSpecNode::describe($mediaTypeSpec['schema']),
                    ),
                ]);
            }

            if (array_key_exists('itemSchema', $mediaTypeSpec) && MalformedSpecNode::isMalformed($mediaTypeSpec['itemSchema'])) {
                return new RequestBodyValidationResult([
                    sprintf(
                        "Malformed 'requestBody.content[\"%s\"].itemSchema' for %s %s in '%s' spec: expected object, got %s.",
                        $mediaType,
                        $method,
                        $matchedPath,
                        $specName,
                        MalformedSpecNode::describe($mediaTypeSpec['itemSchema']),
                    ),
                ]);
            }

            // Checked for every declared media type, like its `schema` /
            // `itemSchema` siblings above: a malformed `encoding` node is a
            // broken spec whether or not this particular request carried a
            // body that would reach it (issue #405).
            if (array_key_exists('encoding', $mediaTypeSpec) && MalformedSpecNode::isMalformed($mediaTypeSpec['encoding'])) {
                return new RequestBodyValidationResult([
                    sprintf(
                        "Malformed 'requestBody.content[\"%s\"].encoding' for %s %s in '%s' spec: expected object, got %s.",
                        $mediaType,
                        $method,
                        $matchedPath,
                        $specName,
                        MalformedSpecNode::describe($mediaTypeSpec['encoding']),
                    ),
                ]);
            }

            /** @var array<string, mixed> $encodingSpec */
            $encodingSpec = $mediaTypeSpec['encoding'] ?? [];
            foreach ($encodingSpec as $partName => $partEncoding) {
                if (MalformedSpecNode::isMalformed($partEncoding)) {
                    return new RequestBodyValidationResult([
                        sprintf(
                            "Malformed 'requestBody.content[\"%s\"].encoding[\"%s\"]' for %s %s in '%s' spec: expected object, got %s.",
                            $mediaType,
                            $partName,
                            $method,
                            $matchedPath,
                            $specName,
                            MalformedSpecNode::describe($partEncoding),
                        ),
                    ]);
                }
            }
        }

        // When the actual request Content-Type is provided, handle content negotiation:
        // non-JSON types are checked for spec presence only, while JSON-compatible types
        // fall through to schema validation against the first JSON media type in the spec.
        if ($contentType !== null) {
            $normalizedType = ContentTypeMatcher::normalizeMediaType($contentType);

            if (!ContentTypeMatcher::isJsonContentType($normalizedType)) {
                $matchedKey = ContentTypeMatcher::findContentTypeKey($normalizedType, $content);
                if ($matchedKey !== null) {
                    if ($required && !$requestBody->present) {
                        return self::missingRequiredBodyResult($specName, $method, $matchedPath, $matchedKey);
                    }

                    if (isset($content[$matchedKey]['itemSchema'])) {
                        return self::unsupportedItemSchemaResult($normalizedType, $matchedKey);
                    }

                    // Form bodies are the one non-JSON family this engine can
                    // still check: the adapter hands over the parsed field map
                    // (or a raw urlencoded string), each field is coerced to
                    // its declared type, and the media type's schema applies
                    // as usual (issue #405).
                    if (isset($content[$matchedKey]['schema']) && FormBodyDecoder::isFormMediaType($normalizedType)) {
                        /** @var array<string, mixed> $mediaTypeSpec */
                        $mediaTypeSpec = $content[$matchedKey];

                        return $this->validateFormBody(
                            $normalizedType,
                            $matchedKey,
                            $mediaTypeSpec,
                            $requestBody,
                            $version,
                            $discriminatorContext,
                            $jsonSchemaDialect,
                        );
                    }

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
                        return new RequestBodyValidationResult(
                            [],
                            sprintf(
                                "request Content-Type '%s' matched non-JSON spec media type '%s', "
                                . 'which declares a schema this validator cannot evaluate (JSON Schema engine only)',
                                $normalizedType,
                                $matchedKey,
                            ),
                            $matchedKey,
                        );
                    }

                    return new RequestBodyValidationResult([], matchedContentType: $matchedKey);
                }

                $defined = implode(', ', array_keys($content));

                return new RequestBodyValidationResult([
                    "Request Content-Type '{$normalizedType}' is not defined for {$method} {$matchedPath} in '{$specName}' spec. Defined content types: {$defined}",
                ]);
            }

            // JSON-compatible request: fall through to existing JSON schema validation.
            // JSON types are treated as interchangeable (e.g. application/vnd.api+json
            // validates against an application/json spec entry) because the schema is
            // the same regardless of the specific JSON media type.
        }

        $jsonContentType = ContentTypeMatcher::findJsonContentType($content);

        // `requestBody.required` constrains whether a body is present on the
        // wire; it does not depend on the selected media-type entry declaring
        // a schema this validator can evaluate. Run this after content-type
        // matching so an unknown actual Content-Type remains the primary
        // diagnostic, but before the no-JSON/no-schema early returns below.
        if ($required && !$requestBody->present) {
            return self::missingRequiredBodyResult($specName, $method, $matchedPath, $jsonContentType);
        }

        // If no JSON-compatible content type is defined, skip body validation.
        // This validator only handles JSON schemas; non-JSON types (e.g. application/xml,
        // application/octet-stream) are outside its scope.
        if ($jsonContentType === null) {
            foreach ($content as $mediaType => $mediaTypeSpec) {
                if (isset($mediaTypeSpec['itemSchema'])) {
                    return self::unsupportedItemSchemaResult((string) $mediaType, (string) $mediaType);
                }
            }

            return new RequestBodyValidationResult([]);
        }

        if (!isset($content[$jsonContentType]['schema'])) {
            if (isset($content[$jsonContentType]['itemSchema'])) {
                return self::unsupportedItemSchemaResult($jsonContentType, $jsonContentType);
            }

            return new RequestBodyValidationResult([], matchedContentType: $jsonContentType);
        }

        // Required absence was rejected before content negotiation. An absent
        // optional body remains acceptable. A literal JSON `null` body is
        // distinct — `->present` is true with a `null` value (issues #246 /
        // #248), so it falls through to schema type-checking below instead of
        // taking this branch.
        if (!$requestBody->present) {
            return new RequestBodyValidationResult([], matchedContentType: $jsonContentType);
        }

        $bodyValue = $requestBody->value;

        /** @var array<string, mixed> $schema */
        $schema = $content[$jsonContentType]['schema'];
        $jsonSchema = OpenApiSchemaConverter::convert($schema, $version, SchemaContext::Request, $discriminatorContext, $jsonSchemaDialect);

        // PHP's `json_decode($json, true)` returns `[]` for both `[]` and `{}`.
        // The Laravel adapter's request decoder uses associative-array decoding,
        // so an empty `{}` body lands here as PHP `[]`. ObjectConverter preserves
        // empty arrays as JSON arrays, so a schema's `type: object` would then
        // reject the body with a misleading "must match the type: object" error.
        // Coerce `[]` → stdClass when the schema explicitly accepts an object so
        // the empty-object-against-type-object case (very common for
        // "create with defaults" bodies) validates. Mirrors the response-side
        // fix at ResponseBodyValidator::validate().
        if ($bodyValue === [] && self::schemaAcceptsObject($schema)) {
            $bodyValue = new stdClass();
        }

        $schemaObject = ObjectConverter::convert($jsonSchema);
        $dataObject = ObjectConverter::convert($bodyValue);

        $violations = $this->runner->validateStructured($schemaObject, $dataObject);

        $errors = [];
        foreach ($violations as $violation) {
            $errors[] = "[{$violation->displayPath()}] {$violation->message}";
        }

        return new RequestBodyValidationResult(
            $errors,
            matchedContentType: $jsonContentType,
            violations: $violations,
        );
    }

    private static function missingRequiredBodyResult(
        string $specName,
        string $method,
        string $matchedPath,
        ?string $matchedContentType = null,
    ): RequestBodyValidationResult {
        return new RequestBodyValidationResult(
            [
                "Request body is empty but {$method} {$matchedPath} defines a required request body in '{$specName}' spec.",
            ],
            matchedContentType: $matchedContentType,
        );
    }

    private static function unsupportedItemSchemaResult(
        string $mediaType,
        ?string $matchedContentType = null,
    ): RequestBodyValidationResult {
        return new RequestBodyValidationResult(
            [],
            sprintf(
                "request Content-Type '%s' uses OpenAPI 3.2 itemSchema streaming semantics; "
                . 'stream items cannot be validated from the buffered request body and were explicitly skipped',
                $mediaType,
            ),
            $matchedContentType,
        );
    }

    /**
     * Whether the schema's top-level type explicitly accepts a JSON object.
     * Handles OAS 3.0 (`type: object`) and OAS 3.1/3.2 (`type: ["object", "null"]`).
     * Composition keywords (`oneOf` / `anyOf` / `allOf`) are intentionally
     * NOT walked — coercion only fires for the unambiguous case so a real
     * type-mismatch error still surfaces for `type: array` schemas where the
     * empty-array body is genuinely correct. Intentional duplicate of the
     * same-named helper on the response-side body validator; if you change
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

    /**
     * Validate a `multipart/form-data` or `application/x-www-form-urlencoded`
     * body against its media-type schema (issue #405).
     *
     * The body value is the field map the adapter parsed (file parts arriving
     * as {@see UploadedPart}), or a raw urlencoded string. When neither shape
     * is available — an adapter that leaves the body undecoded, or a raw
     * multipart payload this validator will not reassemble — the body stays
     * `Skipped` with a reason rather than counting as a clean pass.
     *
     * @param array<string, mixed> $mediaTypeSpec
     */
    private function validateFormBody(
        string $normalizedType,
        string $matchedKey,
        array $mediaTypeSpec,
        DecodedBody $requestBody,
        OpenApiVersion $version,
        ?DiscriminatorContext $discriminatorContext,
        ?string $jsonSchemaDialect,
    ): RequestBodyValidationResult {
        // An absent optional body has nothing to validate; the required case
        // was already rejected before content negotiation reached here.
        if (!$requestBody->present) {
            return new RequestBodyValidationResult([], matchedContentType: $matchedKey);
        }

        $fields = FormBodyDecoder::toFieldMap($requestBody->value, $normalizedType);

        if ($fields === null) {
            return new RequestBodyValidationResult(
                [],
                sprintf(
                    "request Content-Type '%s' matched spec media type '%s', but the form body was not "
                    . 'available as a parsed field map, so its schema was not applied',
                    $normalizedType,
                    $matchedKey,
                ),
                $matchedKey,
            );
        }

        /** @var array<string, mixed> $schema */
        $schema = $mediaTypeSpec['schema'];
        /** @var array<string, mixed> $encoding */
        $encoding = is_array($mediaTypeSpec['encoding'] ?? null) ? $mediaTypeSpec['encoding'] : [];

        [$data, $errors, $unverifiable] = FormBodyDecoder::prepare($fields, $schema, $encoding, $normalizedType);

        // A part whose media type cannot be resolved makes the whole body
        // unreliable: its value may not even be the shape the subschema
        // expects. Report a real contradiction if one was already found,
        // otherwise skip loudly rather than validate around the hole.
        if ($unverifiable !== []) {
            if ($errors !== []) {
                return new RequestBodyValidationResult($errors, matchedContentType: $matchedKey);
            }

            return new RequestBodyValidationResult(
                [],
                sprintf(
                    "request Content-Type '%s' matched spec media type '%s', but its body schema was not applied: %s",
                    $normalizedType,
                    $matchedKey,
                    implode('; ', $unverifiable),
                ),
                $matchedKey,
            );
        }

        $jsonSchema = OpenApiSchemaConverter::convert($schema, $version, SchemaContext::Request, $discriminatorContext, $jsonSchemaDialect);

        // An empty field map is an empty JSON object, not an empty array —
        // same coercion the JSON path applies so `type: object` still matches.
        $dataObject = ObjectConverter::convert($data === [] ? new stdClass() : $data);

        $violations = $this->runner->validateStructured(ObjectConverter::convert($jsonSchema), $dataObject);
        foreach ($violations as $violation) {
            $errors[] = "[{$violation->displayPath()}] {$violation->message}";
        }

        return new RequestBodyValidationResult(
            $errors,
            matchedContentType: $matchedKey,
            violations: $violations,
        );
    }
}
