<?php

declare(strict_types=1);

namespace Studio\Gesso\Laravel\RouteParity;

use Illuminate\Routing\Route;
use Illuminate\Support\Str;
use Studio\Gesso\Spec\OpenApiOperationResolver;
use Studio\Gesso\Spec\OpenApiPathMatcher;
use Studio\Gesso\Validation\Support\MalformedSpecNode;

use function array_keys;
use function array_map;
use function array_slice;
use function array_unique;
use function array_values;
use function count;
use function explode;
use function implode;
use function in_array;
use function is_array;
use function is_string;
use function ltrim;
use function preg_match;
use function preg_replace;
use function rtrim;
use function str_starts_with;
use function strtolower;
use function strtoupper;
use function trim;

/**
 * Compares Laravel's registered routes with resolved OpenAPI operations.
 *
 * @internal Not part of the package's public API.
 *
 * @phpstan-type Filters array{prefix?: ?string, middleware?: list<string>, domains?: list<string>, excluded_route_names?: list<string>, excluded_operation_ids?: list<string>, excluded_openapi_paths?: list<string>}
 * @phpstan-type Operation array{spec: string, method: string, openapi_path: string, normalized_path: string, operation_id: ?string}
 * @phpstan-type RegisteredRoute array{method: string, route_uri: string, normalized_path: string, route_name: ?string, domain: ?string}
 */
final class LaravelRouteParityAnalyzer
{
    /**
     * @param array<string, array<string, mixed>> $specs
     * @param iterable<Route> $routes
     * @param list<string> $stripPrefixes
     * @param Filters $filters
     */
    public function analyze(array $specs, iterable $routes, array $stripPrefixes = [], array $filters = []): RouteParityResult
    {
        $operations = $this->collectOperations($specs);
        $registered = [];
        $ambiguous = [];
        $unsupported = [];
        $documentedMethods = array_unique(array_map(
            static fn(array $operation): string => $operation['method'],
            $operations,
        ));

        foreach ($routes as $route) {
            if (!$this->passesFilters($route, $filters)) {
                continue;
            }

            if ($route->isFallback) {
                $ambiguous[] = [
                    'kind' => 'fallback_route',
                    'method' => null,
                    'route_uri' => $this->displayUri($route->uri()),
                    'route_name' => $route->getName(),
                    'domain' => $route->getDomain(),
                    'candidates' => [],
                ];

                continue;
            }

            $methods = array_values(array_unique(array_map(
                static fn(string $method): string => strtoupper($method),
                $route->methods(),
            )));
            $hasGet = in_array('GET', $methods, true);

            foreach ($methods as $method) {
                if (!in_array(strtolower($method), OpenApiOperationResolver::FIXED_OPERATION_FIELDS, true) &&
                    !in_array($method, $documentedMethods, true)) {
                    $unsupported[] = [
                        'method' => $method,
                        'route_uri' => $this->displayUri($route->uri()),
                        'route_name' => $route->getName(),
                        'domain' => $route->getDomain(),
                        'reason' => 'The method is not a fixed OpenAPI operation and is not declared by the selected specs.',
                    ];

                    continue;
                }

                foreach ($this->expandOptionalUri($route->uri()) as $uri) {
                    $normalizedPath = $this->normalizeRoutePath($uri, $stripPrefixes);
                    if ($method === 'HEAD' && $hasGet && !$this->hasOperation($operations, 'HEAD', $normalizedPath)) {
                        continue;
                    }

                    $registered[] = [
                        'method' => $method,
                        'route_uri' => $this->displayUri($uri),
                        'normalized_path' => $normalizedPath,
                        'route_name' => $route->getName(),
                        'domain' => $route->getDomain(),
                    ];
                }
            }
        }

        $matched = [];
        $routeOnly = [];
        $matchedOperationIndexes = [];

        foreach ($registered as $route) {
            $candidateIndexes = [];
            foreach ($operations as $index => $operation) {
                if ($route['method'] === $operation['method'] &&
                    $route['normalized_path'] === $operation['normalized_path']) {
                    $candidateIndexes[] = $index;
                }
            }

            if ($candidateIndexes === []) {
                $routeOnly[] = $this->routeOnlyEntry($route);

                continue;
            }

            $candidateIndexesBySpec = [];
            foreach ($candidateIndexes as $index) {
                $candidateIndexesBySpec[$operations[$index]['spec']][] = $index;
            }

            foreach ($candidateIndexesBySpec as $indexesForSpec) {
                if (count($indexesForSpec) > 1) {
                    $candidates = [];
                    foreach ($indexesForSpec as $index) {
                        $operation = $operations[$index];
                        $matchedOperationIndexes[$index] = true;
                        $candidates[] = [
                            'spec' => $operation['spec'],
                            'method' => $operation['method'],
                            'openapi_path' => $operation['openapi_path'],
                        ];
                    }
                    $ambiguous[] = [
                        'kind' => 'multiple_openapi_operations',
                        'method' => $route['method'],
                        'route_uri' => $route['route_uri'],
                        'route_name' => $route['route_name'],
                        'domain' => $route['domain'],
                        'candidates' => $candidates,
                    ];

                    continue;
                }

                $index = $indexesForSpec[0];
                $operation = $operations[$index];
                $matchedOperationIndexes[$index] = true;
                $matched[] = [
                    'spec' => $operation['spec'],
                    'method' => $operation['method'],
                    'openapi_path' => $operation['openapi_path'],
                    'operation_id' => $operation['operation_id'],
                    'route_uri' => $route['route_uri'],
                    'route_name' => $route['route_name'],
                    'domain' => $route['domain'],
                ];
            }
        }

        $openApiOnly = [];
        $externalOperations = [];
        foreach ($operations as $index => $operation) {
            if (isset($matchedOperationIndexes[$index])) {
                continue;
            }

            $entry = [
                'spec' => $operation['spec'],
                'method' => $operation['method'],
                'openapi_path' => $operation['openapi_path'],
                'operation_id' => $operation['operation_id'],
            ];

            if ($this->isExcludedOperation($operation, $filters)) {
                $externalOperations[] = $entry;

                continue;
            }

            $openApiOnly[] = $entry;
        }

        return new RouteParityResult(
            specs: array_keys($specs),
            matched: $matched,
            documentedButNotRegistered: $openApiOnly,
            externalOperations: $externalOperations,
            registeredButUndocumented: $routeOnly,
            ambiguous: $ambiguous,
            unsupported: $unsupported,
        );
    }

    /**
     * Documented-side exclusions only classify unmatched operations. A route
     * that implements an excluded operation is still reported as matched.
     *
     * @param Operation $operation
     * @param Filters $filters
     */
    private function isExcludedOperation(array $operation, array $filters): bool
    {
        $operationId = $operation['operation_id'];
        if ($operationId !== null) {
            foreach ($filters['excluded_operation_ids'] ?? [] as $pattern) {
                if (Str::is($pattern, $operationId)) {
                    return true;
                }
            }
        }

        foreach ($filters['excluded_openapi_paths'] ?? [] as $pattern) {
            if (Str::is($pattern, $operation['openapi_path'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, array<string, mixed>> $specs
     *
     * @return list<Operation>
     */
    private function collectOperations(array $specs): array
    {
        $operations = [];

        foreach ($specs as $specName => $spec) {
            $paths = is_array($spec['paths'] ?? null) ? $spec['paths'] : [];
            foreach ($paths as $path => $pathItem) {
                if (!is_string($path) || MalformedSpecNode::isMalformed($pathItem)) {
                    continue;
                }

                foreach (OpenApiOperationResolver::declaredOperations($pathItem) as $declared) {
                    if (MalformedSpecNode::isMalformed($declared['operation'])) {
                        continue;
                    }

                    $method = OpenApiOperationResolver::normalizeMethodForKey($declared['method']);
                    $operations[] = [
                        'spec' => $specName,
                        'method' => $method,
                        'openapi_path' => $path,
                        'normalized_path' => $this->normalizeTemplate($path),
                        'operation_id' => is_string($declared['operation']['operationId'] ?? null)
                            ? $declared['operation']['operationId']
                            : null,
                    ];
                }
            }
        }

        return $operations;
    }

    /** @param Filters $filters */
    private function passesFilters(Route $route, array $filters): bool
    {
        $prefix = trim((string) ($filters['prefix'] ?? ''), '/');
        $uri = trim($route->uri(), '/');
        if ($prefix !== '' && $uri !== $prefix && !str_starts_with($uri, $prefix . '/')) {
            return false;
        }

        $requiredMiddleware = $filters['middleware'] ?? [];
        $routeMiddleware = $route->gatherMiddleware();
        foreach ($requiredMiddleware as $middleware) {
            if (!in_array($middleware, $routeMiddleware, true)) {
                return false;
            }
        }

        $domains = $filters['domains'] ?? [];
        if ($domains !== [] && !in_array($route->getDomain() ?? '', $domains, true)) {
            return false;
        }

        $routeName = $route->getName();
        foreach ($filters['excluded_route_names'] ?? [] as $pattern) {
            if ($routeName !== null && Str::is($pattern, $routeName)) {
                return false;
            }
        }

        return true;
    }

    /** @return list<string> */
    private function expandOptionalUri(string $uri): array
    {
        $segments = $uri === '/' ? [] : explode('/', trim($uri, '/'));
        $required = [];
        $optional = [];

        foreach ($segments as $segment) {
            if (preg_match('/^\{[^{}]+\?\}$/', $segment) === 1) {
                $optional[] = $segment;

                continue;
            }

            $required[] = $segment;
        }

        if ($optional === []) {
            return [$uri];
        }

        $expanded = [];
        for ($included = 0; $included <= count($optional); $included++) {
            $expanded[] = implode('/', [...$required, ...array_slice($optional, 0, $included)]);
        }

        return $expanded;
    }

    /** @param list<string> $stripPrefixes */
    private function normalizeRoutePath(string $uri, array $stripPrefixes): string
    {
        $requestPath = $this->displayUri($uri);
        $matcher = new OpenApiPathMatcher([], $stripPrefixes);
        $normalized = $matcher->normalizeRequestPath($requestPath)['path'];

        return $this->normalizeTemplate($normalized);
    }

    private function normalizeTemplate(string $path): string
    {
        $normalized = preg_replace('/\{[^{}]+\??\}/', '{}', $path);

        return $normalized === null ? $path : (rtrim($normalized, '/') ?: '/');
    }

    /** @param list<Operation> $operations */
    private function hasOperation(array $operations, string $method, string $normalizedPath): bool
    {
        foreach ($operations as $operation) {
            if ($operation['method'] === $method && $operation['normalized_path'] === $normalizedPath) {
                return true;
            }
        }

        return false;
    }

    private function displayUri(string $uri): string
    {
        $trimmed = ltrim($uri, '/');

        return $trimmed === '' ? '/' : '/' . $trimmed;
    }

    /**
     * @param RegisteredRoute $route
     *
     * @return array{method: string, route_uri: string, route_name: ?string, domain: ?string}
     */
    private function routeOnlyEntry(array $route): array
    {
        return [
            'method' => $route['method'],
            'route_uri' => $route['route_uri'],
            'route_name' => $route['route_name'],
            'domain' => $route['domain'],
        ];
    }
}
