<?php

declare(strict_types=1);

namespace Studio\Gesso\Validation\Request;

use const JSON_THROW_ON_ERROR;

use Closure;
use JsonException;
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
use Studio\Gesso\Validation\Support\SchemaViolation;

use function array_column;
use function array_filter;
use function array_key_exists;
use function array_keys;
use function array_values;
use function count;
use function implode;
use function in_array;
use function is_array;
use function is_string;
use function json_decode;
use function sprintf;
use function str_replace;
use function str_starts_with;

/**
 * @internal Not part of the package's public API. Do not use from user code.
 */
final class RequestBodyValidator
{
    /**
     * How many readings of one body the validator will enumerate. Each
     * unresolved part multiplies the count, and a form with more than a
     * handful of them is far outside what a real spec declares. Past the
     * ceiling nothing is enumerated: a partially checked product cannot tell a
     * real violation from one an unchecked combination would excuse, so only
     * what no reading can reach ({@see self::unconditionalSchema()}) is still
     * reported and the rest is left unconfirmed.
     */
    private const MAX_BODY_READINGS = 64;

    /**
     * The keywords through which one property's value can decide a violation
     * reported elsewhere in the object, and the reference keywords that can
     * hide one out of reach ({@see self::unconditionalSchema()}).
     */
    private const CONDITIONAL_KEYWORDS = [
        'if',
        'then',
        'else',
        'anyOf',
        'oneOf',
        'not',
        'unevaluatedProperties',
        'unevaluatedItems',
    ];

    private const REFERENCE_KEYWORDS = ['$ref', '$dynamicRef', '$recursiveRef'];

    /** Keywords whose value is a map of name => schema, not a schema. */
    private const SCHEMA_MAP_KEYWORDS = ['properties', 'patternProperties', 'dependentSchemas', '$defs', 'definitions'];

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

    /**
     * Every other reading of the body the contract still allows.
     *
     * Each unverifiable part chooses its media type independently, so the
     * readings are built per part and then combined: two parts declaring
     * `application/json, text/plain` really can arrive as text and JSON in
     * either order, and a probe added because one part may be an image must
     * not be forced onto a part whose contract only allows JSON or text.
     *
     * A part's own readings are the raw string the adapter handed over (the
     * text reading, which the caller already validated), the JSON value its
     * bytes decode to when JSON is among its candidates, and — when a
     * candidate cannot be materialised here at all (XML, an image, the octet
     * stream) — two shape probes standing in for "could be anything".
     * An array part keeps its container throughout: `encoding` applies to the
     * items, so only the leaves are re-read.
     *
     * @param array<string, mixed> $data
     * @param array<string, array{reason: string, candidates: list<string>}> $unverifiable
     *
     * @return null|list<array<string, mixed>> null when the parts combine into
     *                                         more readings than this validator enumerates — nothing about the
     *                                         body's remaining violations can be confirmed then
     */
    private static function alternativeReadings(array $data, array $unverifiable): ?array
    {
        $perPart = [];
        foreach ($unverifiable as $partName => $part) {
            if (array_key_exists($partName, $data)) {
                $perPart[$partName] = self::readingsForPart($data[$partName], $part['candidates']);
            }
        }

        $variants = [$data];
        foreach ($perPart as $partName => $readings) {
            $combined = [];
            foreach ($variants as $variant) {
                foreach ($readings as $reading) {
                    $variant[$partName] = $reading;
                    $combined[] = $variant;
                }
            }

            // The full product is exponential in the number of unresolved
            // parts. Past the ceiling the readings are not enumerated at all:
            // a partial sweep would leave combinations unchecked, and any one
            // of them may be the reading that excuses a violation, so the
            // caller reports the body as unchecked instead of failing it.
            if (count($combined) > self::MAX_BODY_READINGS) {
                return null;
            }

            $variants = $combined;
        }

        return array_values(array_filter(
            $variants,
            static fn(array $variant): bool => $variant !== $data,
        ));
    }

    /**
     * The values a single part may hold, its raw reading first.
     *
     * @param list<string> $candidates
     *
     * @return list<mixed>
     */
    private static function readingsForPart(mixed $value, array $candidates): array
    {
        $readings = [$value];
        $opaque = false;

        foreach ($candidates as $candidate) {
            if (ContentTypeMatcher::isJsonContentType($candidate)) {
                $readings[] = self::readLeaves($value, self::jsonReading(...));

                continue;
            }

            $opaque = $opaque || $candidate !== 'text/plain';
        }

        if ($opaque) {
            $readings[] = self::readLeaves($value, static fn(mixed $_leaf): mixed => new stdClass());
            $readings[] = self::readLeaves($value, static fn(mixed $_leaf): mixed => 0);
        }

        $distinct = [];
        foreach ($readings as $reading) {
            foreach ($distinct as $seen) {
                if ($seen === $reading) {
                    continue 2;
                }
            }

            $distinct[] = $reading;
        }

        return $distinct;
    }

    /**
     * Apply a reading to the value's leaves, leaving array containers in place
     * — a multipart array property stays an array however its items are read.
     *
     * @param Closure(mixed): mixed $transform
     */
    private static function readLeaves(mixed $value, Closure $transform): mixed
    {
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $value[$key] = self::readLeaves($item, $transform);
            }

            return $value;
        }

        return $transform($value);
    }

    /**
     * The JSON reading of a leaf: the value its bytes decode to, or the leaf
     * unchanged when they are not JSON at all. Decoded with objects left as
     * objects so an empty `{}` does not arrive as an empty array.
     */
    private static function jsonReading(mixed $value): mixed
    {
        if (!is_string($value)) {
            return $value;
        }

        try {
            return json_decode($value, false, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return $value;
        }
    }

    /**
     * The schema reduced to the keywords no part's reading can influence.
     *
     * `if` / `then` / `else`, `anyOf`, `oneOf`, `not` and the `unevaluated*`
     * pair are the keywords whose outcome one property's value can decide for
     * the whole object, and they are the only route by which reading an
     * unresolved part differently changes a violation reported outside that
     * part. What the schema without them still reports — an unconditional
     * `required`, `minProperties`, `additionalProperties`, a plain field's own
     * type — holds under every reading.
     *
     * null when a reference keyword is in the way: the referenced schema is
     * not reachable here, so nothing about it can be called unconditional.
     *
     * @param array<string, mixed> $schema
     *
     * @return null|array<array-key, mixed>
     */
    private static function unconditionalSchema(array $schema): ?array
    {
        return self::containsReference($schema) ? null : self::withoutConditionalKeywords($schema);
    }

    /**
     * @param array<array-key, mixed> $node
     */
    private static function containsReference(array $node): bool
    {
        foreach ($node as $key => $value) {
            if (in_array($key, self::REFERENCE_KEYWORDS, true)) {
                return true;
            }

            if (is_array($value) && self::containsReference($value)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Drop the conditional keywords everywhere below `$node`. Schema-valued
     * maps (`properties`, `$defs`, …) are descended into as maps, so a
     * property literally named `if` keeps its subschema.
     *
     * @param array<array-key, mixed> $node
     *
     * @return array<array-key, mixed>
     */
    private static function withoutConditionalKeywords(array $node): array
    {
        $stripped = [];
        foreach ($node as $key => $value) {
            if (in_array($key, self::CONDITIONAL_KEYWORDS, true)) {
                continue;
            }

            if (in_array($key, self::SCHEMA_MAP_KEYWORDS, true) && is_array($value)) {
                $subschemas = [];
                foreach ($value as $name => $subschema) {
                    $subschemas[$name] = is_array($subschema) ? self::withoutConditionalKeywords($subschema) : $subschema;
                }

                $stripped[$key] = $subschemas;

                continue;
            }

            $stripped[$key] = is_array($value) ? self::withoutConditionalKeywords($value) : $value;
        }

        return $stripped;
    }

    /**
     * Keep only the violations another reading produces too. A violation one
     * reading reports and another does not is decided by the part's
     * unresolved media type, and reporting it would fail a request that an
     * equally permitted reading validates.
     *
     * @param list<SchemaViolation> $violations
     * @param list<SchemaViolation> $alternative
     *
     * @return list<SchemaViolation>
     */
    private static function violationsEveryReadingAgreesOn(array $violations, array $alternative): array
    {
        $fingerprints = [];
        foreach ($alternative as $violation) {
            $fingerprints[self::violationFingerprint($violation)] = true;
        }

        return array_values(array_filter(
            $violations,
            static fn(SchemaViolation $violation): bool => isset($fingerprints[self::violationFingerprint($violation)]),
        ));
    }

    private static function violationFingerprint(SchemaViolation $violation): string
    {
        return $violation->instancePath . "\0" . ($violation->keyword ?? '') . "\0" . $violation->message;
    }

    /**
     * Drop the violations whose instance pointer addresses one of the named
     * top-level properties (or anything below it). Violations reported at the
     * object itself — `required`, `minProperties`, `additionalProperties` —
     * keep their pointer at the parent and therefore survive.
     *
     * @param list<SchemaViolation> $violations
     * @param list<string> $partNames
     *
     * @return list<SchemaViolation>
     */
    private static function withoutViolationsInside(array $violations, array $partNames): array
    {
        $pointers = [];
        foreach ($partNames as $partName) {
            // RFC 6901 escaping, so a part literally named `a/b` is matched
            // as the single property it is rather than as a nested path.
            $pointers[] = '/' . str_replace(['~', '/'], ['~0', '~1'], $partName);
        }

        return array_values(array_filter(
            $violations,
            static function (SchemaViolation $violation) use ($pointers): bool {
                foreach ($pointers as $pointer) {
                    if ($violation->instancePath === $pointer ||
                        str_starts_with($violation->instancePath, $pointer . '/')) {
                        return false;
                    }
                }

                return true;
            },
        ));
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

        $jsonSchema = OpenApiSchemaConverter::convert($schema, $version, SchemaContext::Request, $discriminatorContext, $jsonSchemaDialect);

        // An empty field map is an empty JSON object, not an empty array —
        // same coercion the JSON path applies so `type: object` still matches.
        $dataObject = ObjectConverter::convert($data === [] ? new stdClass() : $data);

        $schemaObject = ObjectConverter::convert($jsonSchema);
        $violations = $this->runner->validateStructured($schemaObject, $dataObject);

        // The body is validated exactly as written — the data and the schema
        // are never rewritten around an unverifiable part, which would change
        // what `minProperties` / `maxProperties` / `additionalProperties` and
        // a composed `required` are counting. Two classes of violation are
        // withheld instead:
        //
        //  * those pointing *into* such a part — its raw value is not
        //    necessarily the shape its own subschema describes;
        //  * those the part's reading decides. A `if` / `oneOf` /
        //    `dependentRequired` branch keyed on the part reports at the
        //    object, not at the part, so a `required` failure at the root can
        //    be an artifact of reading an unresolved part as a raw string
        //    (OAS 3.2 "Handling multiple contentType values"). Re-running the
        //    schema with the part read differently tells the two apart: what
        //    both readings agree on is a real violation, what they disagree
        //    on hinges on a Content-Type the wire did not preserve.
        $reasons = array_column($unverifiable, 'reason');

        if ($unverifiable !== []) {
            $violations = self::withoutViolationsInside($violations, array_keys($unverifiable));
            $readings = self::alternativeReadings($data, $unverifiable);

            if ($readings === null) {
                // Too many combinations to enumerate, so a reading nobody
                // checked may be the one that excuses a violation. Only the
                // violations a reading can reach are withheld: the schema
                // stripped of every keyword whose outcome another property's
                // value can decide still reports what no part can explain
                // away — an unconditional `required`, `minProperties`, a
                // plain field's own type — and those stay failures.
                $unconditional = self::unconditionalSchema($jsonSchema);
                $confirmed = $unconditional === null ? [] : self::violationsEveryReadingAgreesOn(
                    $violations,
                    $this->runner->validateStructured(ObjectConverter::convert($unconditional), $dataObject),
                );

                if (count($confirmed) !== count($violations)) {
                    $reasons[] = sprintf(
                        'the media types of %d unresolved parts combine into more readings than the %d this '
                        . 'validator enumerates, so the violations depending on them were left unconfirmed',
                        count($unverifiable),
                        self::MAX_BODY_READINGS,
                    );
                }

                $violations = $confirmed;
            } else {
                foreach ($readings as $reading) {
                    if ($violations === []) {
                        break;
                    }

                    $violations = self::violationsEveryReadingAgreesOn(
                        $violations,
                        $this->runner->validateStructured($schemaObject, ObjectConverter::convert($reading)),
                    );
                }
            }
        }

        foreach ($violations as $violation) {
            $errors[] = "[{$violation->displayPath()}] {$violation->message}";
        }

        // A genuine contradiction outranks the skip; only an otherwise clean
        // body is reported as unchecked, so the unverifiable part is never
        // counted as a pass either.
        if ($errors === [] && $unverifiable !== []) {
            return new RequestBodyValidationResult(
                [],
                sprintf(
                    "request Content-Type '%s' matched spec media type '%s', but part of its body schema was not applied: %s",
                    $normalizedType,
                    $matchedKey,
                    implode('; ', $reasons),
                ),
                $matchedKey,
            );
        }

        return new RequestBodyValidationResult(
            $errors,
            matchedContentType: $matchedKey,
            violations: $violations,
        );
    }
}
