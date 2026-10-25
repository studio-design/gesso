<?php

declare(strict_types=1);

namespace Studio\Gesso\Validation\Response;

use Studio\Gesso\OpenApiVersion;
use Studio\Gesso\Spec\OpenApiPathMatcher;

/**
 * Operation-level result of {@see ResponseSchemaResolver::locateOperation()}
 * (stage 0) or {@see ResponseSchemaResolver::resolveOperation()} (stage 1):
 * the spec is loaded, the path matched, and the operation resolved. Stage 1
 * additionally verifies the `responses` map; a stage-0 `Resolved` carries
 * `responses === null`, which {@see ResponseSchemaResolver::resolveResponseSchema()}
 * refuses. The stage-1 boundary exists because the response validator
 * applies its skip-by-status-code policy between operation resolution and
 * status-key resolution; callers without that policy use
 * {@see ResponseSchemaResolver::resolve()} instead.
 *
 * `outcome` is restricted to `Resolved`, `MalformedSpec`, `PathNotFound`,
 * and `MethodNotDefined`. On failures `message` carries the exact diagnostic
 * the response validator historically produced; the spec-document fields are
 * null. On `Resolved` the document fields are set (bar a stage-0
 * `responses`) and `message` is null.
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
     * @param null|array<string, string> $pathVariables raw `{placeholder}` captures, undecoded
     *                                                  (see {@see OpenApiPathMatcher::matchWithVariables()})
     * @param null|array<string, mixed> $pathSpec the matched path item
     * @param null|array<string, mixed> $operation the resolved operation node
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
        public ?array $pathVariables = null,
        public ?array $pathSpec = null,
        public ?array $operation = null,
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
     * @param array<string, string> $pathVariables
     * @param array<string, mixed> $pathSpec
     * @param array<string, mixed> $operation
     * @param null|array<array-key, mixed> $responses null when produced by
     *                                                {@see ResponseSchemaResolver::locateOperation()}:
     *                                                the map has not been structurally verified
     */
    public static function resolved(
        string $specName,
        string $method,
        string $matchedPath,
        array $spec,
        OpenApiVersion $version,
        string $jsonSchemaDialect,
        array $pathVariables,
        array $pathSpec,
        array $operation,
        ?array $responses = null,
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
            pathVariables: $pathVariables,
            pathSpec: $pathSpec,
            operation: $operation,
        );
    }
}
