<?php

declare(strict_types=1);

namespace Studio\Gesso\Validation\Response;

use LogicException;
use stdClass;
use Studio\Gesso\DecodedBody;
use Studio\Gesso\OpenApiResponseValidator;
use Studio\Gesso\Validation\Support\ObjectConverter;
use Studio\Gesso\Validation\Support\SchemaValidatorRunner;
use Studio\Gesso\Validation\Support\TypeCoercer;

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
}
