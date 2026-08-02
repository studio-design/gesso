<?php

declare(strict_types=1);

namespace Studio\Gesso\Fuzz;

use InvalidArgumentException;
use Studio\Gesso\Spec\OpenApiOperationResolver;
use Studio\Gesso\Validation\Response\ResponseSchemaResolution;
use Studio\Gesso\Validation\Response\ResponseSchemaResolutionOutcome;
use Studio\Gesso\Validation\Response\ResponseSchemaResolver;

use function array_keys;
use function array_map;
use function sprintf;

/**
 * Generate deterministic, branch-complete valid payloads for one response
 * schema selected by `(method, path, status, content type)`.
 */
final class OpenApiResponseExplorer
{
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
            $resolution->contentType === null
        ) {
            throw self::unsupportedResolution($specName, $method, $path, $status, $resolution);
        }

        $schema = $resolution->convertedSchema();
        $matchedPath = $resolution->matchedPath;
        $resolvedContentType = $resolution->contentType;
        $replayMethod = OpenApiOperationResolver::normalizeMethodForKey($method);
        $effectiveSeed = $seed ?? 0;
        $plannedCases = BranchCompleteCaseGenerator::generate($schema, $effectiveSeed, $extraCases);

        return new GeneratedResponseCases(array_map(
            static function (PlannedSchemaCase $planned, int $caseIndex) use (
                $status,
                $resolvedContentType,
                $effectiveSeed,
                $specName,
                $replayMethod,
                $matchedPath,
                $schema,
                $extraCases,
            ): GeneratedResponseCase {
                $pointer = $planned->plan->targetPointer;
                $branch = $planned->plan->targetBranch;

                return new GeneratedResponseCase(
                    body: $planned->value,
                    status: $status,
                    contentType: $resolvedContentType,
                    seed: $effectiveSeed,
                    caseIndex: $caseIndex,
                    pinnedBranch: $pointer !== null && $branch !== null ? $pointer . '@' . $branch : null,
                    specName: $specName,
                    method: $replayMethod,
                    matchedPath: $matchedPath,
                    schema: $schema,
                    extraCases: $extraCases,
                );
            },
            $plannedCases,
            array_keys($plannedCases),
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
