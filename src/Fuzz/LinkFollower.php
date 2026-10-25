<?php

declare(strict_types=1);

namespace Studio\Gesso\Fuzz;

use InvalidArgumentException;
use RuntimeException;
use Studio\Gesso\DecodedBody;
use Studio\Gesso\HttpMethod;
use Studio\Gesso\Internal\ObservedResponse;
use Studio\Gesso\Internal\OperationIndex;
use Studio\Gesso\Internal\RuntimeExpressionContext;
use Studio\Gesso\Internal\RuntimeExpressionEvaluator;
use Studio\Gesso\Internal\RuntimeExpressionException;
use Studio\Gesso\Spec\OpenApiRefResolver;
use Studio\Gesso\Validation\Support\ContentTypeMatcher;
use Studio\Gesso\Validation\Support\HeaderNormalizer;
use Studio\Gesso\Validation\Support\MalformedSpecNode;
use Studio\Gesso\Validation\Support\SpecResponseKeyResolver;

use function array_key_exists;
use function array_keys;
use function array_map;
use function array_merge;
use function array_unique;
use function array_values;
use function count;
use function explode;
use function implode;
use function in_array;
use function is_array;
use function is_string;
use function rawurldecode;
use function sprintf;
use function str_starts_with;
use function strcasecmp;
use function strtolower;
use function substr;

/**
 * Resolves explicit OpenAPI response `links` into follow-up cases for
 * whole-spec exploration. A malformed or unsupported link fails loudly;
 * nothing is silently skipped.
 *
 * @internal Links exploration implementation detail; not public API.
 */
final class LinkFollower
{
    private const LOCATIONS = ['path', 'query', 'header', 'cookie'];
    private readonly OperationIndex $operations;

    /** @var array<string, array<string, mixed>> */
    private readonly array $paths;

    /**
     * @param array<string, mixed> $spec
     */
    public function __construct(
        private readonly string $specName,
        array $spec,
    ) {
        $this->operations = OperationIndex::build($specName, $spec);
        $this->paths = SpecPathsPreflight::validatedPaths($specName, $spec);
    }

    /**
     * @param string $parentPath concrete request path (no query) of the parent
     * @param string $childPath concrete request path (no query) of the follow-up
     */
    public static function check(HttpMethod $parentMethod, int $parentStatus, string $parentPath, HttpMethod $childMethod, int $childStatus, string $childPath): ?LinkChainCheck
    {
        // 202 Accepted only queues the work (RFC 9110 §15.3.3), so an immediate read-back proves nothing.
        $parentSucceeded = $parentStatus >= 200 && $parentStatus < 300 && $parentStatus !== 202;
        if (!$parentSucceeded || $childMethod !== HttpMethod::GET) {
            return null;
        }

        // Only a read of the very resource that was deleted proves use-after-free.
        if ($parentMethod === HttpMethod::DELETE && $parentPath === $childPath && $childStatus >= 200 && $childStatus < 300) {
            return LinkChainCheck::UseAfterFree;
        }

        if (in_array($parentMethod, [HttpMethod::POST, HttpMethod::PUT, HttpMethod::PATCH], true) && in_array($childStatus, [404, 410], true)) {
            return LinkChainCheck::EnsureResourceAvailability;
        }

        return null;
    }

    /**
     * Called after exclusions, so an excluded undispatchable target is not an error.
     *
     * @param array{method: string, path: string, operation: array<string, mixed>} $target
     */
    public static function assertDispatchable(string $name, array $target): void
    {
        if (HttpMethod::tryFrom($target['method']) === null) {
            throw new RuntimeException(sprintf(
                "Link '%s': target method %s is not dispatchable; exclude it (excludeMethods(), excludeOperations()) or author the link differently.",
                $name,
                $target['method'],
            ));
        }
    }

    /**
     * The `links` declared on the response `$status` of an operation.
     *
     * @return array<array-key, array<string, mixed>> link name → Link Object; a numeric name is an int key
     */
    public function links(string $method, string $path, int $status): array
    {
        $responses = $this->operation($method, $path)['operation']['responses'] ?? [];
        $key = is_array($responses) ? SpecResponseKeyResolver::resolve((string) $status, $responses) : null;
        $response = $key === null ? null : $responses[$key];
        $links = is_array($response) && array_key_exists('links', $response) ? $response['links'] : [];
        if (MalformedSpecNode::isMalformed($links)) {
            throw new RuntimeException(sprintf(
                "Malformed 'links' on %s %s response %s in '%s' spec: expected object, got %s.",
                $method,
                $path,
                (string) $key,
                $this->specName,
                MalformedSpecNode::describe($links),
            ));
        }

        $resolved = [];
        foreach ($links as $name => $link) {
            if (MalformedSpecNode::isMalformed($link)) {
                throw new RuntimeException(sprintf("Malformed link '%s' on %s %s in '%s' spec: expected object.", (string) $name, $method, $path, $this->specName));
            }

            /** @var array<string, mixed> $link */
            $resolved[$name] = $link;
        }

        return $resolved;
    }

    /**
     * The operation a link points at.
     *
     * @param array<string, mixed> $link
     *
     * @return array{method: string, path: string, operation: array<string, mixed>}
     */
    public function target(string $name, array $link): array
    {
        $hasId = array_key_exists('operationId', $link);
        $hasRef = array_key_exists('operationRef', $link);
        if ($hasId === $hasRef) {
            throw new RuntimeException(sprintf("Link '%s' must set exactly one of operationId or operationRef.", $name));
        }

        if ($hasId) {
            $target = is_string($link['operationId']) ? $this->operations->find($link['operationId']) : null;
            if ($target === null) {
                throw new RuntimeException(sprintf("Link '%s' names an unknown operationId in '%s' spec.", $name, $this->specName));
            }
        } else {
            $ref = $link['operationRef'];
            if (!is_string($ref) || !str_starts_with($ref, '#/')) {
                throw new RuntimeException(sprintf("Link '%s': external operationRef is not supported; use operationId or a local #/paths pointer.", $name));
            }

            $segments = array_map(
                static fn(string $segment): string => OpenApiRefResolver::unescapePointerSegment(rawurldecode($segment)),
                explode('/', substr($ref, 2)),
            );
            $target = $this->operations->findByPointer($segments)
                ?? throw new RuntimeException(sprintf("Link '%s': operationRef '%s' does not point at an operation.", $name, $ref));
        }

        return $target;
    }

    /**
     * One deterministic generated case for the link target.
     *
     * @param array{method: string, path: string, operation: array<string, mixed>} $target
     */
    public function generate(string $name, array $target, int $seed): ExploredCase
    {
        try {
            foreach (OpenApiEndpointExplorer::explore($this->specName, $target['method'], $target['path'], 1, $seed) as $case) {
                return $case;
            }
        } catch (InvalidArgumentException $e) {
            throw new RuntimeException(sprintf("Link '%s': cannot generate a request for %s %s: %s", $name, $target['method'], $target['path'], $e->getMessage()), 0, $e);
        }

        throw new RuntimeException(sprintf("Link '%s': no request generated for %s %s.", $name, $target['method'], $target['path']));
    }

    /**
     * Override the generated case with the link's `parameters` and
     * `requestBody`, evaluated against the parent exchange.
     *
     * @param array<string, mixed> $link
     * @param array{method: string, path: string, operation: array<string, mixed>} $target
     */
    public function apply(string $name, array $link, array $target, ExploredCase $child, ExploredCase $parent, ObservedResponse $response): ExploredCase
    {
        $context = new RuntimeExpressionContext(
            url: $parent->uri(),
            method: $parent->method->value,
            requestHeaders: HeaderNormalizer::normalize($parent->headers),
            requestQuery: $parent->query,
            requestPath: $parent->pathParams,
            requestBody: $this->sendsBody($parent) ? DecodedBody::present($parent->body) : DecodedBody::absent(),
            response: $response,
        );

        $parameters = array_key_exists('parameters', $link) ? $link['parameters'] : [];
        if (MalformedSpecNode::isMalformed($parameters)) {
            throw new RuntimeException(sprintf("Link '%s': 'parameters' must be an object.", $name));
        }

        $overrides = ['path' => [], 'query' => [], 'header' => [], 'cookie' => []];
        foreach ($parameters as $key => $expression) {
            [$in, $parameter] = $this->locate($name, (string) $key, $target);
            $overrides[$in][$parameter] = $this->evaluate($name, (string) $key, $expression, $context);
        }

        $child = $child
            ->withPathParams(array_merge($child->pathParams, $overrides['path']))
            ->withQuery(array_merge($child->query, $overrides['query']))
            ->withHeaders(self::mergeHeaders($child->headers, $overrides['header']))
            ->withCookies(array_merge($child->cookies, $overrides['cookie']));

        return array_key_exists('requestBody', $link)
            ? $child->withBody($this->evaluate($name, 'requestBody', $link['requestBody'], $context))
            : $child;
    }

    /**
     * Header names are case-insensitive: an authored link value replaces a
     * generated header of any spelling instead of being sent beside it.
     *
     * @param array<string, mixed> $headers
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private static function mergeHeaders(array $headers, array $overrides): array
    {
        foreach ($overrides as $name => $value) {
            foreach (array_keys($headers) as $existing) {
                if (strcasecmp($existing, $name) === 0) {
                    unset($headers[$existing]);
                }
            }

            $headers[$name] = $value;
        }

        return $headers;
    }

    /**
     * A null body is the JSON `null` when the operation declares a media type
     * the explorer synthesized it for; otherwise the request carried no body.
     */
    private function sendsBody(ExploredCase $case): bool
    {
        if ($case->body !== null) {
            return true;
        }

        $content = $this->operation($case->method->value, $case->matchedPath)['operation']['requestBody']['content'] ?? null;
        if (!is_array($content)) {
            return false;
        }

        foreach ($content as $mediaType => $entry) {
            if (is_array($entry) && is_array($entry['schema'] ?? null) && ContentTypeMatcher::normalizeMediaType((string) $mediaType) === ContentTypeMatcher::normalizeMediaType($case->contentType)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{method: string, path: string, operation: array<string, mixed>}
     */
    private function operation(string $method, string $path): array
    {
        return $this->operations->findByPointer(['paths', $path, strtolower($method)])
            ?? $this->operations->findByPointer(['paths', $path, 'additionalOperations', $method])
            ?? throw new RuntimeException(sprintf("No operation %s %s in '%s' spec.", $method, $path, $this->specName));
    }

    /**
     * Resolve a link parameter key: `path.id` names its location, a bare
     * name must match exactly one target parameter.
     *
     * @param array{method: string, path: string, operation: array<string, mixed>} $target
     *
     * @return array{0: string, 1: string}
     */
    private function locate(string $link, string $key, array $target): array
    {
        $parts = explode('.', $key, 2);
        if (count($parts) === 2 && in_array($parts[0], self::LOCATIONS, true)) {
            return [$parts[0], $parts[1]];
        }

        $pathLevel = $this->paths[$target['path']]['parameters'] ?? [];
        $operationLevel = $target['operation']['parameters'] ?? [];
        $declared = array_merge(is_array($pathLevel) ? $pathLevel : [], is_array($operationLevel) ? $operationLevel : []);
        $locations = [];
        foreach ($declared as $parameter) {
            if (is_array($parameter) && ($parameter['name'] ?? null) === $key && is_string($parameter['in'] ?? null)) {
                $locations[] = $parameter['in'];
            }
        }

        $locations = array_values(array_unique($locations));
        if (count($locations) !== 1) {
            throw new RuntimeException(sprintf(
                "Link '%s': parameter '%s' %s; qualify it as path.%s, query.%s, header.%s or cookie.%s.",
                $link,
                $key,
                $locations === [] ? 'matches no parameter of the target operation' : 'is ambiguous (' . implode(', ', $locations) . ')',
                $key,
                $key,
                $key,
                $key,
            ));
        }

        return [$locations[0], $key];
    }

    private function evaluate(string $link, string $key, mixed $value, RuntimeExpressionContext $context): mixed
    {
        try {
            return RuntimeExpressionEvaluator::evaluateValue($value, $context);
        } catch (RuntimeExpressionException $e) {
            throw new RuntimeException(sprintf("Link '%s' %s: %s", $link, $key, $e->getMessage()), 0, $e);
        }
    }
}
