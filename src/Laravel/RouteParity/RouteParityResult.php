<?php

declare(strict_types=1);

namespace Studio\Gesso\Laravel\RouteParity;

use JsonSerializable;

use function count;

/**
 * Stable result returned by the Laravel route-parity analyzer.
 *
 * @internal Not part of the package's public API. The versioned JSON emitted
 *           by `openapi:routes` is the supported machine-readable contract.
 *
 * @phpstan-type MatchedEntry array{spec: string, method: string, openapi_path: string, operation_id: ?string, route_uri: string, route_name: ?string, domain: ?string}
 * @phpstan-type OpenApiOnlyEntry array{spec: string, method: string, openapi_path: string, operation_id: ?string}
 * @phpstan-type RouteOnlyEntry array{method: string, route_uri: string, route_name: ?string, domain: ?string}
 * @phpstan-type AmbiguousEntry array{kind: string, method: ?string, route_uri: string, route_name: ?string, domain: ?string, candidates: list<array{spec: string, method: string, openapi_path: string}>}
 * @phpstan-type UnsupportedEntry array{method: string, route_uri: string, route_name: ?string, domain: ?string, reason: string}
 */
final readonly class RouteParityResult implements JsonSerializable
{
    /**
     * @param list<string> $specs
     * @param list<MatchedEntry> $matched
     * @param list<OpenApiOnlyEntry> $documentedButNotRegistered
     * @param list<OpenApiOnlyEntry> $externalOperations
     * @param list<RouteOnlyEntry> $registeredButUndocumented
     * @param list<AmbiguousEntry> $ambiguous
     * @param list<UnsupportedEntry> $unsupported
     */
    public function __construct(
        public array $specs,
        public array $matched,
        public array $documentedButNotRegistered,
        public array $externalOperations,
        public array $registeredButUndocumented,
        public array $ambiguous,
        public array $unsupported,
    ) {}

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'schema_version' => 2,
            'specs' => $this->specs,
            'summary' => [
                'matched' => count($this->matched),
                'documented_but_not_registered' => count($this->documentedButNotRegistered),
                'external_operations' => count($this->externalOperations),
                'registered_but_undocumented' => count($this->registeredButUndocumented),
                'ambiguous' => count($this->ambiguous),
                'unsupported' => count($this->unsupported),
            ],
            'matched' => $this->matched,
            'documented_but_not_registered' => $this->documentedButNotRegistered,
            'external_operations' => $this->externalOperations,
            'registered_but_undocumented' => $this->registeredButUndocumented,
            'ambiguous' => $this->ambiguous,
            'unsupported' => $this->unsupported,
        ];
    }
}
