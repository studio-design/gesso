<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Validation\Request;

use stdClass;
use Studio\OpenApiContractTesting\OpenApiVersion;
use Studio\OpenApiContractTesting\SchemaContext;
use Studio\OpenApiContractTesting\Spec\OpenApiSchemaConverter;
use Studio\OpenApiContractTesting\Validation\Support\ContentTypeMatcher;
use Studio\OpenApiContractTesting\Validation\Support\ObjectConverter;
use Studio\OpenApiContractTesting\Validation\Support\SchemaValidatorRunner;

use function array_key_exists;
use function array_keys;
use function implode;
use function in_array;
use function is_array;
use function is_string;

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
     * Returns an empty list when the body is acceptable (including when the
     * spec defines no body, no content, no JSON content type, or no schema).
     * Hard spec-level errors (malformed `requestBody` / `content`) are
     * reported as standard error entries so the orchestrator can accumulate
     * them alongside other validators' errors.
     *
     * @param array<string, mixed> $operation
     *
     * @return string[]
     */
    public function validate(
        string $specName,
        string $method,
        string $matchedPath,
        array $operation,
        mixed $requestBody,
        ?string $contentType,
        OpenApiVersion $version,
    ): array {
        // OpenAPI: a missing requestBody means the operation accepts no body — treat as success.
        if (!isset($operation['requestBody'])) {
            return [];
        }

        // A present-but-non-array requestBody signals a malformed spec (stray scalar).
        // Contract-testing tools should surface this, not mask it as "no body".
        if (!is_array($operation['requestBody'])) {
            return [
                "Malformed 'requestBody' for {$method} {$matchedPath} in '{$specName}' spec: expected object, got scalar.",
            ];
        }

        /** @var array<string, mixed> $requestBodySpec */
        $requestBodySpec = $operation['requestBody'];

        $required = ($requestBodySpec['required'] ?? false) === true;

        if (!isset($requestBodySpec['content'])) {
            return [];
        }

        if (!is_array($requestBodySpec['content'])) {
            return [
                "Malformed 'requestBody.content' for {$method} {$matchedPath} in '{$specName}' spec: expected object, got scalar.",
            ];
        }

        /** @var array<string, mixed> $content */
        $content = $requestBodySpec['content'];

        foreach ($content as $mediaType => $mediaTypeSpec) {
            // The @var on $content narrows values to array, but PHPDoc is unchecked at
            // runtime — a malformed spec like `content: {"application/json": "oops"}`
            // would TypeError on downstream array accesses. Surface it as a loud spec
            // error instead, matching the sibling guard on `requestBody.content` above.
            if (!is_array($mediaTypeSpec)) {
                return [
                    "Malformed 'requestBody.content[\"{$mediaType}\"]' for {$method} {$matchedPath} in '{$specName}' spec: expected object, got scalar.",
                ];
            }

            // `schema: "oops"` (or any other non-array scalar) would slip past the
            // downstream `isset(...['schema'])` presence check and reach
            // OpenApiSchemaConverter::convert() as a scalar, producing a confusing
            // TypeError instead of a spec-level error. array_key_exists rather than
            // isset so an explicit `schema: null` is also flagged.
            if (array_key_exists('schema', $mediaTypeSpec) && !is_array($mediaTypeSpec['schema'])) {
                return [
                    "Malformed 'requestBody.content[\"{$mediaType}\"].schema' for {$method} {$matchedPath} in '{$specName}' spec: expected object, got scalar.",
                ];
            }
        }

        // When the actual request Content-Type is provided, handle content negotiation:
        // non-JSON types are checked for spec presence only, while JSON-compatible types
        // fall through to schema validation against the first JSON media type in the spec.
        if ($contentType !== null) {
            $normalizedType = ContentTypeMatcher::normalizeMediaType($contentType);

            if (!ContentTypeMatcher::isJsonContentType($normalizedType)) {
                if (ContentTypeMatcher::isContentTypeInSpec($normalizedType, $content)) {
                    return [];
                }

                $defined = implode(', ', array_keys($content));

                return [
                    "Request Content-Type '{$normalizedType}' is not defined for {$method} {$matchedPath} in '{$specName}' spec. Defined content types: {$defined}",
                ];
            }

            // JSON-compatible request: fall through to existing JSON schema validation.
            // JSON types are treated as interchangeable (e.g. application/vnd.api+json
            // validates against an application/json spec entry) because the schema is
            // the same regardless of the specific JSON media type.
        }

        $jsonContentType = ContentTypeMatcher::findJsonContentType($content);

        // If no JSON-compatible content type is defined, skip body validation.
        // This validator only handles JSON schemas; non-JSON types (e.g. application/xml,
        // application/octet-stream) are outside its scope.
        if ($jsonContentType === null) {
            return [];
        }

        if (!isset($content[$jsonContentType]['schema'])) {
            return [];
        }

        if ($requestBody === null) {
            if (!$required) {
                return [];
            }

            return [
                "Request body is empty but {$method} {$matchedPath} defines a required JSON request body schema in '{$specName}' spec.",
            ];
        }

        /** @var array<string, mixed> $schema */
        $schema = $content[$jsonContentType]['schema'];
        $jsonSchema = OpenApiSchemaConverter::convert($schema, $version, SchemaContext::Request);

        // PHP's `json_decode($json, true)` returns `[]` for both `[]` and `{}`.
        // The Laravel adapter's request decoder uses associative-array decoding,
        // so an empty `{}` body lands here as PHP `[]`. ObjectConverter preserves
        // empty arrays as JSON arrays, so a schema's `type: object` would then
        // reject the body with a misleading "must match the type: object" error.
        // Coerce `[]` → stdClass when the schema explicitly accepts an object so
        // the empty-object-against-type-object case (very common for
        // "create with defaults" bodies) validates. Mirrors the response-side
        // fix at ResponseBodyValidator::validate().
        if ($requestBody === [] && self::schemaAcceptsObject($schema)) {
            $requestBody = new stdClass();
        }

        $schemaObject = ObjectConverter::convert($jsonSchema);
        $dataObject = ObjectConverter::convert($requestBody);

        $formatted = $this->runner->validate($schemaObject, $dataObject);

        $errors = [];
        foreach ($formatted as $path => $messages) {
            foreach ($messages as $message) {
                $errors[] = "[{$path}] {$message}";
            }
        }

        return $errors;
    }

    /**
     * Whether the schema's top-level type explicitly accepts a JSON object.
     * Handles OAS 3.0 (`type: object`) and OAS 3.1 (`type: ["object", "null"]`).
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
}
