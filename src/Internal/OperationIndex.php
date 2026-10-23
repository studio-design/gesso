<?php

declare(strict_types=1);

namespace Studio\Gesso\Internal;

use InvalidArgumentException;
use Studio\Gesso\Fuzz\SpecPathsPreflight;
use Studio\Gesso\Spec\OpenApiOperationResolver;

use function count;
use function in_array;
use function is_array;
use function is_string;
use function sprintf;

/**
 * Operation lookup by `operationId` and by `#/paths/...` pointer segments for
 * one loaded OpenAPI document. Malformed `paths` fail through the shared
 * {@see SpecPathsPreflight}; a duplicated `operationId` fails loudly because
 * Links and Arazzo targets would otherwise be ambiguous.
 *
 * @internal Runtime-expression plumbing shared by Links exploration and the Arazzo runner; not public API.
 */
final readonly class OperationIndex
{
    /**
     * @param array<string, array{method: string, path: string, operation: array<string, mixed>}> $byId
     * @param array<string, array<string, array{method: string, path: string, operation: array<string, mixed>}>> $byPath path template → method field (`get`, or the exact additionalOperations key) → entry
     */
    private function __construct(
        private array $byId,
        private array $byPath,
    ) {}

    /**
     * @param array<string, mixed> $spec resolved document from OpenApiSpecLoader::load()
     *
     * @throws InvalidArgumentException on malformed `paths` or a duplicated `operationId`
     */
    public static function build(string $specName, array $spec): self
    {
        $byId = [];
        $byPath = [];
        foreach (SpecPathsPreflight::validatedPaths($specName, $spec) as $path => $pathItem) {
            foreach (OpenApiOperationResolver::declaredOperations($pathItem) as $declared) {
                /** @var array<string, mixed> $operation validated by the preflight */
                $operation = $declared['operation'];
                $entry = ['method' => $declared['method'], 'path' => $path, 'operation' => $operation];
                $isFixed = in_array($declared['location'], OpenApiOperationResolver::FIXED_OPERATION_FIELDS, true);
                $byPath[$path][$isFixed ? $declared['location'] : "\0" . $declared['method']] = $entry;

                $id = $operation['operationId'] ?? null;
                if (!is_string($id)) {
                    continue;
                }

                if (isset($byId[$id])) {
                    throw new InvalidArgumentException(sprintf(
                        "Duplicate operationId '%s' in spec '%s': %s %s and %s %s.",
                        $id,
                        $specName,
                        $byId[$id]['method'],
                        $byId[$id]['path'],
                        $declared['method'],
                        $path,
                    ));
                }

                $byId[$id] = $entry;
            }
        }

        return new self($byId, $byPath);
    }

    /**
     * @return null|array{method: string, path: string, operation: array<string, mixed>}
     */
    public function find(string $operationId): ?array
    {
        return $this->byId[$operationId] ?? null;
    }

    /**
     * @param list<string> $segments decoded pointer segments: `['paths', <template>, <method>]` or `['paths', <template>, 'additionalOperations', <METHOD>]`
     *
     * @return null|array{method: string, path: string, operation: array<string, mixed>}
     */
    public function findByPointer(array $segments): ?array
    {
        if (($segments[0] ?? null) !== 'paths' || !isset($segments[1], $segments[2]) || !is_array($this->byPath[$segments[1]] ?? null)) {
            return null;
        }

        $operations = $this->byPath[$segments[1]];
        if (count($segments) === 3 && in_array($segments[2], OpenApiOperationResolver::FIXED_OPERATION_FIELDS, true)) {
            return $operations[$segments[2]] ?? null;
        }

        if (count($segments) === 4 && $segments[2] === 'additionalOperations') {
            return $operations["\0" . $segments[3]] ?? null;
        }

        return null;
    }
}
