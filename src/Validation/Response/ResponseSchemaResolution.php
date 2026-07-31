<?php

declare(strict_types=1);

namespace Studio\Gesso\Validation\Response;

use LogicException;
use Studio\Gesso\OpenApiVersion;
use Studio\Gesso\SchemaContext;
use Studio\Gesso\Spec\OpenApiSchemaConverter;
use Studio\Gesso\Validation\Support\DiscriminatorContext;

use function sprintf;

/**
 * Result of resolving a response schema by `(method, path, status, content
 * type)` through {@see ResponseSchemaResolver} (issue #442).
 *
 * Field availability follows the outcome:
 *
 *  - `message` carries the failure diagnostic — verbatim what the response
 *    validator historically rendered — for every non-`Resolved` outcome
 *    except the deliberate skips and empty successes (`NoContent`,
 *    `NoJsonContent`, `MissingSchema`, `NonJsonSchema`,
 *    `ItemSchemaStreaming`).
 *  - `skipReason` is set on `NonJsonSchema` / `ItemSchemaStreaming` only:
 *    the media type matched but its schema is deliberately not evaluated.
 *  - `responseSpec` is the raw `responses[<statusKey>]` node, available once
 *    the entry resolved as an object (content-level outcomes and later).
 *  - `schema` is the raw media-type `schema` node on `Resolved` only;
 *    {@see convertedSchema()} lowers it on demand.
 *
 * Conversion is deliberately lazy: the response validator reports an absent
 * body *before* converting the schema, so an eagerly-converted (and
 * possibly throwing) schema would change which diagnostic wins. Callers that
 * only need the metadata never pay for — or trip over — the conversion.
 *
 * @internal Not part of the package's public API. Do not use from user code.
 */
final readonly class ResponseSchemaResolution
{
    /**
     * @param null|array<string, mixed> $responseSpec
     * @param null|array<string, mixed> $schema
     */
    private function __construct(
        public ResponseSchemaResolutionOutcome $outcome,
        public ?string $matchedPath,
        public ?string $statusKey,
        public ?string $contentType,
        public ?string $message,
        public ?string $skipReason,
        public ?array $responseSpec,
        public ?array $schema,
        private ?OpenApiVersion $version,
        private ?string $jsonSchemaDialect,
        private ?DiscriminatorContext $discriminatorContext,
    ) {}

    /**
     * Map a failed first-stage resolution into the composed result shape so
     * {@see ResponseSchemaResolver::resolve()} reports one type.
     */
    public static function fromOperationFailure(ResponseOperationResolution $operation): self
    {
        if ($operation->outcome === ResponseSchemaResolutionOutcome::Resolved) {
            throw new LogicException('fromOperationFailure() requires a failed operation resolution.');
        }

        return new self(
            $operation->outcome,
            $operation->matchedPath,
            statusKey: null,
            contentType: null,
            message: $operation->message,
            skipReason: null,
            responseSpec: null,
            schema: null,
            version: null,
            jsonSchemaDialect: null,
            discriminatorContext: null,
        );
    }

    public static function statusNotDeclared(string $matchedPath, string $message): self
    {
        return self::failure(ResponseSchemaResolutionOutcome::StatusNotDeclared, $matchedPath, null, $message);
    }

    public static function malformedResponse(string $matchedPath, string $statusKey, string $message): self
    {
        return self::failure(ResponseSchemaResolutionOutcome::MalformedResponse, $matchedPath, $statusKey, $message);
    }

    /**
     * @param array<string, mixed> $responseSpec
     */
    public static function malformedContent(
        string $matchedPath,
        string $statusKey,
        array $responseSpec,
        string $message,
    ): self {
        return new self(
            ResponseSchemaResolutionOutcome::MalformedContent,
            $matchedPath,
            $statusKey,
            contentType: null,
            message: $message,
            skipReason: null,
            responseSpec: $responseSpec,
            schema: null,
            version: null,
            jsonSchemaDialect: null,
            discriminatorContext: null,
        );
    }

    /**
     * @param array<string, mixed> $responseSpec
     */
    public static function contentTypeNotDeclared(
        string $matchedPath,
        string $statusKey,
        array $responseSpec,
        string $message,
    ): self {
        return new self(
            ResponseSchemaResolutionOutcome::ContentTypeNotDeclared,
            $matchedPath,
            $statusKey,
            contentType: null,
            message: $message,
            skipReason: null,
            responseSpec: $responseSpec,
            schema: null,
            version: null,
            jsonSchemaDialect: null,
            discriminatorContext: null,
        );
    }

    /**
     * @param array<string, mixed> $responseSpec
     */
    public static function noContent(string $matchedPath, string $statusKey, array $responseSpec): self
    {
        return self::withoutSchema(ResponseSchemaResolutionOutcome::NoContent, $matchedPath, $statusKey, null, $responseSpec);
    }

    /**
     * @param array<string, mixed> $responseSpec
     */
    public static function noJsonContent(string $matchedPath, string $statusKey, array $responseSpec): self
    {
        return self::withoutSchema(ResponseSchemaResolutionOutcome::NoJsonContent, $matchedPath, $statusKey, null, $responseSpec);
    }

    /**
     * @param array<string, mixed> $responseSpec
     */
    public static function missingSchema(
        string $matchedPath,
        string $statusKey,
        string $contentType,
        array $responseSpec,
    ): self {
        return self::withoutSchema(ResponseSchemaResolutionOutcome::MissingSchema, $matchedPath, $statusKey, $contentType, $responseSpec);
    }

    /**
     * @param array<string, mixed> $responseSpec
     */
    public static function nonJsonSchema(
        string $matchedPath,
        string $statusKey,
        string $contentType,
        array $responseSpec,
        string $skipReason,
    ): self {
        return self::skipped(ResponseSchemaResolutionOutcome::NonJsonSchema, $matchedPath, $statusKey, $contentType, $responseSpec, $skipReason);
    }

    /**
     * @param array<string, mixed> $responseSpec
     */
    public static function itemSchemaStreaming(
        string $matchedPath,
        string $statusKey,
        string $contentType,
        array $responseSpec,
        string $skipReason,
    ): self {
        return self::skipped(ResponseSchemaResolutionOutcome::ItemSchemaStreaming, $matchedPath, $statusKey, $contentType, $responseSpec, $skipReason);
    }

    /**
     * @param array<string, mixed> $responseSpec
     * @param array<string, mixed> $schema
     */
    public static function resolved(
        string $matchedPath,
        string $statusKey,
        string $contentType,
        array $responseSpec,
        array $schema,
        OpenApiVersion $version,
        string $jsonSchemaDialect,
        DiscriminatorContext $discriminatorContext,
    ): self {
        return new self(
            ResponseSchemaResolutionOutcome::Resolved,
            $matchedPath,
            $statusKey,
            $contentType,
            message: null,
            skipReason: null,
            responseSpec: $responseSpec,
            schema: $schema,
            version: $version,
            jsonSchemaDialect: $jsonSchemaDialect,
            discriminatorContext: $discriminatorContext,
        );
    }

    /**
     * Convert the resolved raw schema for validation, preserving the schema
     * dialect and discriminator enforcement selected at resolution time.
     *
     * Throws the converter's usual `RuntimeException` subclasses for schemas
     * it rejects (unsupported dialect, malformed discriminator, ...) — the
     * caller decides whether that is a test failure or a fatal error.
     *
     * @return array<string, mixed>
     */
    public function convertedSchema(): array
    {
        if ($this->outcome !== ResponseSchemaResolutionOutcome::Resolved ||
            $this->schema === null ||
            $this->version === null
        ) {
            throw new LogicException(sprintf(
                'convertedSchema() is only available on a Resolved response schema resolution; got %s.',
                $this->outcome->name,
            ));
        }

        return OpenApiSchemaConverter::convert(
            $this->schema,
            $this->version,
            SchemaContext::Response,
            $this->discriminatorContext,
            $this->jsonSchemaDialect,
        );
    }

    private static function failure(
        ResponseSchemaResolutionOutcome $outcome,
        string $matchedPath,
        ?string $statusKey,
        string $message,
    ): self {
        return new self(
            $outcome,
            $matchedPath,
            $statusKey,
            contentType: null,
            message: $message,
            skipReason: null,
            responseSpec: null,
            schema: null,
            version: null,
            jsonSchemaDialect: null,
            discriminatorContext: null,
        );
    }

    /**
     * @param array<string, mixed> $responseSpec
     */
    private static function withoutSchema(
        ResponseSchemaResolutionOutcome $outcome,
        string $matchedPath,
        string $statusKey,
        ?string $contentType,
        array $responseSpec,
    ): self {
        return new self(
            $outcome,
            $matchedPath,
            $statusKey,
            $contentType,
            message: null,
            skipReason: null,
            responseSpec: $responseSpec,
            schema: null,
            version: null,
            jsonSchemaDialect: null,
            discriminatorContext: null,
        );
    }

    /**
     * @param array<string, mixed> $responseSpec
     */
    private static function skipped(
        ResponseSchemaResolutionOutcome $outcome,
        string $matchedPath,
        string $statusKey,
        string $contentType,
        array $responseSpec,
        string $skipReason,
    ): self {
        return new self(
            $outcome,
            $matchedPath,
            $statusKey,
            $contentType,
            message: null,
            skipReason: $skipReason,
            responseSpec: $responseSpec,
            schema: null,
            version: null,
            jsonSchemaDialect: null,
            discriminatorContext: null,
        );
    }
}
