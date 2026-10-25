<?php

declare(strict_types=1);

namespace Studio\Gesso\Validation\Response;

use LogicException;
use stdClass;
use Studio\Gesso\DecodedBody;
use Studio\Gesso\OpenApiResponseValidator;
use Studio\Gesso\OpenApiVersion;
use Studio\Gesso\Validation\Support\FormBodyDecoder;
use Studio\Gesso\Validation\Support\ObjectConverter;
use Studio\Gesso\Validation\Support\SchemaValidatorRunner;
use Studio\Gesso\Validation\Support\TypeCoercer;

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
     * Validate the decoded response body against a `Resolved` (JSON) or
     * `FormSchema` (urlencoded) response-schema resolution. Content negotiation, media-type guards, and schema
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
        $isForm = $resolution->outcome === ResponseSchemaResolutionOutcome::FormSchema;
        if (($resolution->outcome !== ResponseSchemaResolutionOutcome::Resolved && !$isForm) ||
            $jsonContentType === null ||
            $schema === null
        ) {
            throw new LogicException(sprintf(
                'ResponseBodyValidator requires a Resolved or FormSchema response schema resolution; got %s.',
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
                    sprintf(
                        "Response body is empty but %s %s (status %d) defines a %s response schema in '%s' spec.",
                        $method,
                        $matchedPath,
                        $statusCode,
                        $isForm ? 'form' : 'JSON-compatible',
                        $specName,
                    ),
                ],
                $jsonContentType,
            );
        }

        if ($isForm) {
            return $this->validateFormBody($resolution, $jsonContentType, $schema, $responseBody);
        }

        $bodyValue = $responseBody->value;

        $jsonSchema = $resolution->convertedSchema();

        // Only legacy assoc-array callers need the ambiguous [] -> {} shim.
        // Adapters retain JSON types, so a wire [] must stay an array.
        if (!$responseBody->preservesJsonTypes && $bodyValue === [] && TypeCoercer::acceptsObject($schema)) {
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
     * Parse an urlencoded response body (the adapters hand over the raw
     * string; direct callers may pass a field map) and validate it with the
     * request-side form rules, `writeOnly` forbidden (issue #488).
     *
     * @param array<string, mixed> $schema
     */
    private function validateFormBody(
        ResponseSchemaResolution $resolution,
        string $contentType,
        array $schema,
        DecodedBody $responseBody,
    ): ResponseBodyValidationResult {
        $fields = FormBodyDecoder::toFieldMap($responseBody->value, FormBodyDecoder::URLENCODED);
        if ($fields === null) {
            return new ResponseBodyValidationResult([], $contentType, sprintf(
                "response Content-Type matched spec media type '%s', but the form body was not available as a "
                . 'parsed field map or raw urlencoded string, so its schema was not applied',
                $contentType,
            ));
        }

        $version = $resolution->version();
        // OAS 3.0 / 3.1: the Media Type `encoding` "SHALL only apply to
        // Request Body Objects"; 3.2 applies it to any form media type.
        $media = $resolution->responseSpec['content'][$contentType] ?? null;
        /** @var array<string, mixed> $encoding */
        $encoding = $version === OpenApiVersion::V3_2 && is_array($media) && is_array($media['encoding'] ?? null)
            ? $media['encoding']
            : [];
        $rawValues = is_string($responseBody->value) ? FormBodyDecoder::rawUrlencodedValues($responseBody->value) : [];

        // urlencoded never yields unverifiable parts or header skips — both
        // are multipart-only — so only the data and errors matter here.
        [$data, $errors] = FormBodyDecoder::prepare(
            $fields,
            $schema,
            $encoding,
            FormBodyDecoder::URLENCODED,
            $version ?? OpenApiVersion::V3_1,
            $rawValues,
        );

        $dataObject = ObjectConverter::convert($data === [] ? new stdClass() : $data);
        $violations = $this->runner->validateStructured(ObjectConverter::convert($resolution->convertedSchema()), $dataObject);
        foreach ($violations as $violation) {
            $errors[] = "[{$violation->displayPath()}] {$violation->message}";
        }

        return new ResponseBodyValidationResult($errors, $contentType, violations: $violations, formData: $dataObject);
    }
}
