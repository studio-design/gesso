<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Spec;

use Studio\OpenApiContractTesting\Validation\Support\MalformedSpecNode;

use function array_key_exists;
use function in_array;
use function is_array;
use function is_string;
use function strtolower;
use function strtoupper;

/**
 * Resolves fixed Path Item operations and OpenAPI 3.2
 * `additionalOperations` through one shared lookup path.
 *
 * @internal Not part of the package's public API. Do not use from user code.
 */
final class OpenApiOperationResolver
{
    /** @var list<string> */
    public const FIXED_OPERATION_FIELDS = [
        'get',
        'put',
        'post',
        'delete',
        'options',
        'head',
        'patch',
        'trace',
        'query',
    ];

    /**
     * @param array<string, mixed> $pathItem
     *
     * @return array{found: bool, operation: mixed, location: string}
     */
    public static function resolve(array $pathItem, string $method): array
    {
        $lowerMethod = strtolower($method);
        if (in_array($lowerMethod, self::FIXED_OPERATION_FIELDS, true) && array_key_exists($lowerMethod, $pathItem)) {
            return [
                'found' => true,
                'operation' => $pathItem[$lowerMethod],
                'location' => $lowerMethod,
            ];
        }

        if (!array_key_exists('additionalOperations', $pathItem)) {
            return ['found' => false, 'operation' => null, 'location' => $lowerMethod];
        }

        $additional = $pathItem['additionalOperations'];
        if (MalformedSpecNode::isMalformed($additional)) {
            return [
                'found' => true,
                'operation' => $additional,
                'location' => 'additionalOperations',
            ];
        }

        foreach ($additional as $declaredMethod => $operation) {
            if (!is_string($declaredMethod) || $declaredMethod !== $method) {
                continue;
            }

            return [
                'found' => true,
                'operation' => $operation,
                'location' => 'additionalOperations["' . $declaredMethod . '"]',
            ];
        }

        return ['found' => false, 'operation' => null, 'location' => $lowerMethod];
    }

    /**
     * Enumerate structurally valid fixed and additional operation entries.
     * Malformed values are retained so callers can surface their own
     * context-specific diagnostics instead of silently omitting them.
     *
     * @param array<string, mixed> $pathItem
     *
     * @return list<array{method: string, operation: mixed, location: string}>
     */
    public static function declaredOperations(array $pathItem): array
    {
        $operations = [];

        foreach (self::FIXED_OPERATION_FIELDS as $field) {
            if (!array_key_exists($field, $pathItem)) {
                continue;
            }

            $operations[] = [
                'method' => strtoupper($field),
                'operation' => $pathItem[$field],
                'location' => $field,
            ];
        }

        $additional = $pathItem['additionalOperations'] ?? null;
        if (!is_array($additional)) {
            return $operations;
        }

        foreach ($additional as $method => $operation) {
            if (!is_string($method) || $method === '') {
                continue;
            }

            $operations[] = [
                'method' => $method,
                'operation' => $operation,
                'location' => 'additionalOperations["' . $method . '"]',
            ];
        }

        return $operations;
    }

    /**
     * Fixed Path Item fields are case-insensitive at request lookup and use
     * their canonical uppercase coverage key. Non-standard methods retain
     * their exact spelling because `additionalOperations` is case-sensitive.
     */
    public static function normalizeMethodForKey(string $method): string
    {
        $lowerMethod = strtolower($method);

        return in_array($lowerMethod, self::FIXED_OPERATION_FIELDS, true)
            ? strtoupper($lowerMethod)
            : $method;
    }
}
