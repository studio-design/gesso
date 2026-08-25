<?php

declare(strict_types=1);

namespace Studio\Gesso\Validation\Response;

use LogicException;
use stdClass;
use Studio\Gesso\DecodedBody;
use Studio\Gesso\OpenApiResponseValidator;
use Studio\Gesso\Validation\Support\ObjectConverter;
use Studio\Gesso\Validation\Support\SchemaValidatorRunner;

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
     * Validate the decoded response body against a `Resolved` response-schema
     * resolution. Content negotiation, media-type guards, and schema
     * selection happen upstream in {@see ResponseSchemaResolver}; every
     * non-`Resolved` outcome is mapped to a result by
     * {@see OpenApiResponseValidator} before this validator is invoked —
     * receiving one here is a wiring bug and throws.
     *
     * Returns a {@see ResponseBodyValidationResult} with an empty `errors`
     * list when the body passes schema validation. Hard failures (empty body
     * against a JSON schema, schema mismatch) are returned as error strings
     * so the orchestrator can assemble the final result.
     */
    public function validate(
        string $specName,
        string $method,
        string $matchedPath,
        int $statusCode,
        ResponseSchemaResolution $resolution,
        DecodedBody $responseBody,
    ): ResponseBodyValidationResult {
        $jsonContentType = $resolution->contentType;
        $schema = $resolution->schema;
        if ($resolution->outcome !== ResponseSchemaResolutionOutcome::Resolved ||
            $jsonContentType === null ||
            $schema === null
        ) {
            throw new LogicException(sprintf(
                'ResponseBodyValidator requires a Resolved response schema resolution; got %s.',
                $resolution->outcome->name,
            ));
        }

        // An absent body fails the contract: this validator only runs once the
        // spec is known to declare a JSON-compatible schema for the response.
        // A literal JSON `null` body is distinct — `$responseBody->present` is
        // true with a `null` value (issues #246 / #248), so it falls through
        // to schema type-checking below instead of taking this branch. The
        // check deliberately runs BEFORE schema conversion so a spec whose
        // schema the converter rejects still reports the missing body first.
        if (!$responseBody->present) {
            return new ResponseBodyValidationResult(
                [
                    "Response body is empty but {$method} {$matchedPath} (status {$statusCode}) defines a JSON-compatible response schema in '{$specName}' spec.",
                ],
                $jsonContentType,
            );
        }

        $bodyValue = $responseBody->value;

        $jsonSchema = $resolution->convertedSchema();

        // PHP's `json_decode($json, true)` returns `[]` for both `[]` and `{}`.
        // The framework adapters decode JSON bodies as objects (assoc=false,
        // issue #559), so `$bodyValue` is `[]` here only in two cases: (a) a
        // caller invokes the public `validate()` entry point directly with a
        // legacy assoc-array body instead of going through an adapter — still
        // supported, see the `$responseBody` param on
        // {@see \Studio\Gesso\OpenApiResponseValidator::validate()} — or (b) the wire
        // body was a genuine empty JSON array `[]`, which decodes to PHP `[]`
        // regardless of the decode mode. ObjectConverter preserves empty
        // arrays as JSON arrays, so the schema's `type: object` would then
        // reject the body with a misleading "must match the type: object"
        // error for case (a). Coerce `[]` → stdClass when the schema
        // explicitly accepts an object so the empty-object-against-type-object
        // case (very common for status acks and "no items yet" responses)
        // validates. Known trade-off for case (b): a genuine wire-level empty
        // array still silently passes `type: object` instead of failing. Issue
        // #560 tracks giving `DecodedBody` a provenance bit for object-shaped
        // wire decodes so unambiguous paths can skip this coercion.
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

        return new ResponseBodyValidationResult($errors, $jsonContentType, violations: $violations);
    }

    /**
     * Whether the schema's top-level type explicitly accepts a JSON object.
     * Handles OAS 3.0 (`type: object`) and OAS 3.1/3.2 (`type: ["object", "null"]`).
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
