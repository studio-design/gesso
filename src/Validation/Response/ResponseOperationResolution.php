<?php

declare(strict_types=1);

namespace Studio\Gesso\Validation\Response;

use Studio\Gesso\OpenApiVersion;

/**
 * First-stage result of {@see ResponseSchemaResolver::resolveOperation()}:
 * the spec is loaded, the path matched, the operation resolved, and its
 * `responses` map structurally verified. The stage boundary exists because
 * the response validator applies its skip-by-status-code policy between
 * operation resolution and status-key resolution; callers without that
 * policy use {@see ResponseSchemaResolver::resolve()} instead.
 *
 * `outcome` is restricted to `Resolved`, `MalformedSpec`, `PathNotFound`,
 * and `MethodNotDefined`. On failures `message` carries the exact diagnostic
 * the response validator historically produced; the spec-document fields are
 * null. On `Resolved` the document fields are set and `message` is null.
 *
 * @internal Not part of the package's public API. Do not use from user code.
 */
final readonly class ResponseOperationResolution
{
    /**
     * @param null|array<string, mixed> $spec the loaded, reference-resolved spec document
     * @param null|array<array-key, mixed> $responses the operation's `responses` map. Keys are
     *                                                `array-key` because PHP coerces numeric-string
     *                                                keys (`"200"`) to int.
     */
    private function __construct(
        public ResponseSchemaResolutionOutcome $outcome,
        public string $specName,
        public string $method,
        public ?string $message,
        public ?string $matchedPath,
        public ?array $spec,
        public ?OpenApiVersion $version,
        public ?string $jsonSchemaDialect,
        public ?array $responses,
    ) {}

    public static function malformedSpec(
        string $specName,
        string $method,
        string $message,
        ?string $matchedPath = null,
    ): self {
        return new self(
            ResponseSchemaResolutionOutcome::MalformedSpec,
            $specName,
            $method,
            $message,
            $matchedPath,
            spec: null,
            version: null,
            jsonSchemaDialect: null,
            responses: null,
        );
    }

    public static function pathNotFound(string $specName, string $method, string $message): self
    {
        return new self(
            ResponseSchemaResolutionOutcome::PathNotFound,
            $specName,
            $method,
            $message,
            matchedPath: null,
            spec: null,
            version: null,
            jsonSchemaDialect: null,
            responses: null,
        );
    }

    public static function methodNotDefined(
        string $specName,
        string $method,
        string $message,
        string $matchedPath,
    ): self {
        return new self(
            ResponseSchemaResolutionOutcome::MethodNotDefined,
            $specName,
            $method,
            $message,
            $matchedPath,
            spec: null,
            version: null,
            jsonSchemaDialect: null,
            responses: null,
        );
    }

    /**
     * @param array<string, mixed> $spec
     * @param array<array-key, mixed> $responses
     */
    public static function resolved(
        string $specName,
        string $method,
        string $matchedPath,
        array $spec,
        OpenApiVersion $version,
        string $jsonSchemaDialect,
        array $responses,
    ): self {
        return new self(
            ResponseSchemaResolutionOutcome::Resolved,
            $specName,
            $method,
            message: null,
            matchedPath: $matchedPath,
            spec: $spec,
            version: $version,
            jsonSchemaDialect: $jsonSchemaDialect,
            responses: $responses,
        );
    }
}
