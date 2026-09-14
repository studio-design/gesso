<?php

declare(strict_types=1);

namespace Studio\Gesso\Fuzz;

use Closure;
use InvalidArgumentException;
use Studio\Gesso\Coverage\SdkExerciseCoverageTracker;
use Studio\Gesso\OpenApiVersion;
use Studio\Gesso\SchemaContext;
use Studio\Gesso\Spec\OpenApiOperationResolver;
use Studio\Gesso\Spec\OpenApiSchemaConverter;
use Studio\Gesso\Spec\OpenApiSchemaDialect;
use Studio\Gesso\Spec\OpenApiSpecLoader;
use Studio\Gesso\Validation\Response\ResponseSchemaResolution;
use Studio\Gesso\Validation\Response\ResponseSchemaResolutionOutcome;
use Studio\Gesso\Validation\Response\ResponseSchemaResolver;
use Studio\Gesso\Validation\Support\DiscriminatorContext;
use Studio\Gesso\Validation\Support\DiscriminatorEnforcement;
use Studio\Gesso\Validation\Support\MalformedSpecNode;

use function array_key_exists;
use function array_keys;
use function array_map;
use function sprintf;

/**
 * Generate deterministic, branch-complete valid payloads for one response
 * schema selected by `(method, path, status, content type)`.
 */
final class OpenApiResponseExplorer
{
    /**
     * Build a deterministic whole-spec SDK response round-trip plan.
     */
    public static function exploreSpec(
        string $specName,
        int $seed = 1,
        int $extraCases = 0,
    ): OpenApiResponseSpecExploration {
        if ($specName === '') {
            throw new InvalidArgumentException('OpenApiResponseExplorer::exploreSpec() requires a non-empty spec name.');
        }
        if ($extraCases < 0) {
            throw new InvalidArgumentException(sprintf(
                'OpenApiResponseExplorer::exploreSpec() requires extraCases >= 0, got %d.',
                $extraCases,
            ));
        }

        return new OpenApiResponseSpecExploration($specName, $seed, $extraCases);
    }

    public static function explore(
        string $specName,
        string $method,
        string $path,
        int $status,
        ?string $contentType = null,
        ?int $seed = null,
        int $extraCases = 0,
    ): GeneratedResponseCases {
        if ($extraCases < 0) {
            throw new InvalidArgumentException(sprintf(
                'OpenApiResponseExplorer::explore() requires extraCases >= 0, got %d.',
                $extraCases,
            ));
        }

        $resolution = (new ResponseSchemaResolver())->resolve(
            $specName,
            $method,
            $path,
            $status,
            $contentType,
        );

        if ($resolution->outcome !== ResponseSchemaResolutionOutcome::Resolved ||
            $resolution->matchedPath === null ||
            $resolution->statusKey === null ||
            $resolution->contentType === null
        ) {
            throw self::unsupportedResolution($specName, $method, $path, $status, $resolution);
        }

        $normalizedMethod = OpenApiOperationResolver::normalizeMethodForKey($method);
        $matchedPath = $resolution->matchedPath;
        $statusKey = $resolution->statusKey;
        $contentTypeKey = $resolution->contentType;

        return self::buildCases(
            schema: $resolution->convertedSchema(),
            effectiveSeed: $seed ?? 0,
            extraCases: $extraCases,
            specName: $specName,
            status: $status,
            contentType: $resolution->contentType,
            method: $normalizedMethod,
            matchedPath: $matchedPath,
            beforeEach: static function () use ($specName, $normalizedMethod, $matchedPath, $statusKey, $contentTypeKey): void {
                SdkExerciseCoverageTracker::current()->recordOn(
                    $specName,
                    $normalizedMethod,
                    $matchedPath,
                    $statusKey,
                    $contentTypeKey,
                );
            },
        );
    }

    /**
     * Generate deterministic, branch-complete valid payloads for one named
     * `components.schemas` entry, converted with response-side semantics.
     */
    public static function exploreComponent(
        string $specName,
        string $schemaName,
        ?int $seed = null,
        int $extraCases = 0,
    ): GeneratedResponseCases {
        if ($extraCases < 0) {
            throw new InvalidArgumentException(sprintf(
                'OpenApiResponseExplorer::exploreComponent() requires extraCases >= 0, got %d.',
                $extraCases,
            ));
        }

        $spec = OpenApiSpecLoader::load($specName);
        $components = array_key_exists('components', $spec) ? $spec['components'] : [];
        self::assertComponentNode($components, 'components', $specName);

        /** @var array<array-key, mixed> $components */
        $schemas = array_key_exists('schemas', $components) ? $components['schemas'] : [];
        self::assertComponentNode($schemas, 'components.schemas', $specName);

        /** @var array<array-key, mixed> $schemas */
        if (!array_key_exists($schemaName, $schemas)) {
            throw new InvalidArgumentException(sprintf(
                "Component schema '%s' is not defined in '%s' spec.",
                $schemaName,
                $specName,
            ));
        }

        $componentSchema = $schemas[$schemaName];
        self::assertComponentNode(
            $componentSchema,
            sprintf('components.schemas["%s"]', $schemaName),
            $specName,
        );

        /** @var array<string, mixed> $componentSchema */
        $version = OpenApiVersion::fromSpec($spec);
        $convertedSchema = OpenApiSchemaConverter::convert(
            $componentSchema,
            $version,
            SchemaContext::Response,
            new DiscriminatorContext($spec, DiscriminatorEnforcement::isEnabled()),
            OpenApiSchemaDialect::fromSpec($spec, $version),
        );

        return self::buildCases(
            schema: $convertedSchema,
            effectiveSeed: $seed ?? 0,
            extraCases: $extraCases,
            specName: $specName,
            schemaName: $schemaName,
        );
    }

    /**
     * @param array<string, mixed> $schema
     */
    private static function buildCases(
        array $schema,
        int $effectiveSeed,
        int $extraCases,
        string $specName,
        ?int $status = null,
        ?string $contentType = null,
        ?string $method = null,
        ?string $matchedPath = null,
        ?string $schemaName = null,
        ?Closure $beforeEach = null,
    ): GeneratedResponseCases {
        $plannedCases = BranchCompleteCaseGenerator::generate($schema, $effectiveSeed, $extraCases);

        $cases = array_map(
            static function (PlannedSchemaCase $planned, int $caseIndex) use (
                $status,
                $contentType,
                $effectiveSeed,
                $specName,
                $method,
                $matchedPath,
                $schema,
                $extraCases,
                $schemaName,
            ): GeneratedResponseCase {
                $pointer = $planned->plan->targetPointer;
                $branch = $planned->plan->targetBranch;

                return new GeneratedResponseCase(
                    body: $planned->value,
                    status: $status,
                    contentType: $contentType,
                    seed: $effectiveSeed,
                    caseIndex: $caseIndex,
                    pinnedBranch: $pointer !== null && $branch !== null ? $pointer . '@' . $branch : null,
                    specName: $specName,
                    method: $method,
                    matchedPath: $matchedPath,
                    schema: $schema,
                    extraCases: $extraCases,
                    schemaName: $schemaName,
                );
            },
            $plannedCases,
            array_keys($plannedCases),
        );

        return new GeneratedResponseCases($cases, $beforeEach);
    }

    private static function assertComponentNode(mixed $node, string $location, string $specName): void
    {
        if (!MalformedSpecNode::isMalformed($node)) {
            return;
        }

        throw new InvalidArgumentException(sprintf(
            "Malformed '%s' in '%s' spec: expected object, got %s.",
            $location,
            $specName,
            MalformedSpecNode::describe($node),
        ));
    }

    private static function unsupportedResolution(
        string $specName,
        string $method,
        string $path,
        int $status,
        ResponseSchemaResolution $resolution,
    ): InvalidArgumentException {
        $reason = $resolution->message ?? $resolution->skipReason ?? match ($resolution->outcome) {
            ResponseSchemaResolutionOutcome::NoContent => 'the response declares no content block',
            ResponseSchemaResolutionOutcome::NoJsonContent => 'the response declares no JSON-compatible content type',
            ResponseSchemaResolutionOutcome::MissingSchema => 'the selected response media type declares no schema',
            ResponseSchemaResolutionOutcome::NonJsonSchema => 'the selected response schema is not JSON-compatible',
            ResponseSchemaResolutionOutcome::ItemSchemaStreaming => 'the response uses itemSchema streaming semantics',
            default => 'the response schema could not be resolved',
        };

        return new InvalidArgumentException(sprintf(
            "Cannot explore response schema for %s %s status %d in '%s' spec: outcome=%s; %s.",
            $method,
            $path,
            $status,
            $specName,
            $resolution->outcome->name,
            $reason,
        ));
    }
}
