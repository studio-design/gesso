<?php

declare(strict_types=1);

namespace Studio\Gesso\Internal;

use Studio\Gesso\DecodedBody;

/**
 * Typed values a runtime expression may read. A null slot means that part
 * of the exchange does not exist yet (ContextUnavailable); an empty map
 * means it exists but has no members (NotFound).
 *
 * @internal Runtime-expression plumbing for Links exploration; not public API.
 */
final readonly class RuntimeExpressionContext
{
    /**
     * @param ?string $url request target as built by the caller
     * @param array<string, mixed> $requestHeaders lower-cased header name → string|list<string>
     * @param array<string, mixed> $requestQuery
     * @param array<string, mixed> $requestPath
     * @param null|array<string, mixed> $inputs workflow inputs; null outside a workflow
     * @param array<string, array<string, mixed>> $stepOutputs stepId → evaluated outputs of the steps run so far
     */
    public function __construct(
        public ?string $url = null,
        public ?string $method = null,
        public array $requestHeaders = [],
        public array $requestQuery = [],
        public array $requestPath = [],
        public ?DecodedBody $requestBody = null,
        public ?ObservedResponse $response = null,
        public ?array $inputs = null,
        public array $stepOutputs = [],
    ) {}
}
