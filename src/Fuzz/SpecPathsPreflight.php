<?php

declare(strict_types=1);

namespace Studio\Gesso\Fuzz;

use InvalidArgumentException;
use Studio\Gesso\Spec\OpenApiOperationResolver;
use Studio\Gesso\Validation\Support\MalformedSpecNode;

use function array_key_exists;
use function is_string;
use function sprintf;

/**
 * Structural preflight over a spec's `paths` shared by the whole-spec
 * exploration plan and the contract-check plan: a mixed valid/malformed
 * document must fail atomically before any request is dispatched.
 *
 * @internal Not part of the package's public API. Do not use from user code.
 */
final class SpecPathsPreflight
{
    /**
     * Validate every structural node required to enumerate the spec before
     * dispatching any request. A mixed valid/malformed document must fail
     * atomically instead of executing the valid prefix and silently omitting
     * the malformed remainder.
     *
     * @param array<string, mixed> $spec
     *
     * @return array<string, array<string, mixed>>
     */
    public static function validatedPaths(string $specName, array $spec): array
    {
        $paths = array_key_exists('paths', $spec) ? $spec['paths'] : [];
        if (MalformedSpecNode::isMalformed($paths)) {
            throw new InvalidArgumentException(sprintf(
                "Malformed 'paths' in '%s' spec: expected object, got %s.",
                $specName,
                MalformedSpecNode::describe($paths),
            ));
        }

        foreach ($paths as $path => $pathItem) {
            if (!is_string($path)) {
                throw new InvalidArgumentException(sprintf(
                    "Malformed 'paths' in '%s' spec: expected string path key.",
                    $specName,
                ));
            }

            if (MalformedSpecNode::isMalformed($pathItem)) {
                throw new InvalidArgumentException(sprintf(
                    "Malformed 'paths[\"%s\"]' in '%s' spec: expected object, got %s.",
                    $path,
                    $specName,
                    MalformedSpecNode::describe($pathItem),
                ));
            }

            if (array_key_exists('additionalOperations', $pathItem) &&
                MalformedSpecNode::isMalformed($pathItem['additionalOperations'])) {
                throw new InvalidArgumentException(sprintf(
                    "Malformed 'paths[\"%s\"].additionalOperations' in '%s' spec: expected object, got %s.",
                    $path,
                    $specName,
                    MalformedSpecNode::describe($pathItem['additionalOperations']),
                ));
            }

            foreach (OpenApiOperationResolver::declaredOperations($pathItem) as $declared) {
                if (!MalformedSpecNode::isMalformed($declared['operation'])) {
                    continue;
                }

                throw new InvalidArgumentException(sprintf(
                    "Malformed 'paths[\"%s\"].%s' for %s %s in '%s' spec: expected object, got %s.",
                    $path,
                    $declared['location'],
                    $declared['method'],
                    $path,
                    $specName,
                    MalformedSpecNode::describe($declared['operation']),
                ));
            }
        }

        return $paths;
    }
}
