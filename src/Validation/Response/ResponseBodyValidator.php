<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Validation\Response;

use stdClass;
use Studio\OpenApiContractTesting\OpenApiResponseValidator;
use Studio\OpenApiContractTesting\OpenApiValidationResult;
use Studio\OpenApiContractTesting\OpenApiVersion;
use Studio\OpenApiContractTesting\SchemaContext;
use Studio\OpenApiContractTesting\Spec\OpenApiSchemaConverter;
use Studio\OpenApiContractTesting\Validation\Support\ContentTypeMatcher;
use Studio\OpenApiContractTesting\Validation\Support\ObjectConverter;
use Studio\OpenApiContractTesting\Validation\Support\SchemaValidatorRunner;

use function array_keys;
use function implode;
use function in_array;
use function is_array;
use function is_string;

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
     * @param array<string, array<string, mixed>> $content the `responses[$status].content` map
     */
    public function validate(
        string $specName,
        string $method,
        string $matchedPath,
        int $statusCode,
        array $content,
        mixed $responseBody,
        ?string $responseContentType,
        OpenApiVersion $version,
    ): ResponseBodyValidationResult {
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

        if ($responseBody === null) {
            return new ResponseBodyValidationResult(
                [
                    "Response body is empty but {$method} {$matchedPath} (status {$statusCode}) defines a JSON-compatible response schema in '{$specName}' spec.",
                ],
                $jsonContentType,
            );
        }

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
        if ($responseBody === [] && self::schemaAcceptsObject($schema)) {
            $responseBody = new stdClass();
        }

        $schemaObject = ObjectConverter::convert($jsonSchema);
        $dataObject = ObjectConverter::convert($responseBody);

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
