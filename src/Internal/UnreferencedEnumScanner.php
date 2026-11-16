<?php

declare(strict_types=1);

namespace Studio\Gesso\Internal;

use Studio\Gesso\Spec\OpenApiRefResolver;

use function array_key_exists;
use function array_map;
use function array_pop;
use function explode;
use function implode;
use function in_array;
use function is_array;
use function is_string;
use function rawurldecode;
use function str_starts_with;
use function substr;

/**
 * The `components.schemas` entries carrying `enum` (the ones enum drift binds
 * to) that no operation under `paths` or `webhooks` reaches through `$ref`.
 *
 * The loaded spec inlines every `$ref`, so reachability is traced on the raw
 * documents, following refs the way {@see OpenApiRefResolver} does: `#/...`
 * within the document, local files through {@see ExternalRefLoader} with the
 * loader's roots. HTTP(S) targets are not fetched again; their URL still
 * matches a component that is itself a `$ref` to it. Operations reach a
 * component through any `$ref` under them, including callbacks.
 *
 * @internal Doctor implementation detail.
 */
final class UnreferencedEnumScanner
{
    private const OPAQUE_KEYS = ['default', 'example', 'examples', 'enum', 'const'];

    /** @var array<string, array<mixed>> raw documents by canonical identifier */
    private array $documents;

    /** @var array<string, array<string, mixed>> */
    private array $cache = [];

    /**
     * @param array<mixed> $raw
     * @param list<string> $allowedLocalRefRoots
     */
    private function __construct(string $entry, array $raw, private readonly array $allowedLocalRefRoots)
    {
        $this->documents = [$entry => $raw];
    }

    /**
     * @param array<string, mixed> $spec the loaded (resolved) entry document
     * @param array<mixed> $raw the same document before `$ref` resolution
     * @param list<string> $allowedLocalRefRoots the roots the loader confined local refs to
     *
     * @return list<string> component names, in document order
     */
    public static function scan(array $spec, array $raw, string $entryPath, array $allowedLocalRefRoots): array
    {
        $schemas = is_array($spec['components'] ?? null) && is_array($spec['components']['schemas'] ?? null) ? $spec['components']['schemas'] : [];
        $enums = [];
        foreach ($schemas as $name => $schema) {
            if (is_array($schema) && array_key_exists('enum', $schema)) {
                $enums[] = (string) $name;
            }
        }
        if ($enums === []) {
            return [];
        }

        $scanner = new self($entryPath, $raw, $allowedLocalRefRoots);
        $visited = [];
        $queue = [[$entryPath, ['paths']], [$entryPath, ['webhooks']]];
        while ($queue !== []) {
            [$document, $segments] = array_pop($queue);
            $key = self::key($document, $segments);
            if (isset($visited[$key])) {
                continue;
            }
            $visited[$key] = true;
            $node = $scanner->node($document, $segments);
            if (is_array($node)) {
                $scanner->collectRefs($node, $document, false, $queue);
            }
        }

        $unreferenced = [];
        foreach ($enums as $name) {
            if (!$scanner->aliasReached([$entryPath, ['components', 'schemas', $name]], $visited)) {
                $unreferenced[] = $name;
            }
        }

        return $unreferenced;
    }

    /** @param list<string> $segments */
    private static function key(string $document, array $segments): string
    {
        return $document . "\0" . implode("\0", $segments);
    }

    /** @return list<string> */
    private static function segments(string $fragment): array
    {
        if ($fragment === '') {
            return [];
        }

        return array_map(
            static fn(string $segment): string => OpenApiRefResolver::unescapePointerSegment(rawurldecode($segment)),
            explode('/', substr($fragment, 1)),
        );
    }

    /**
     * Whether `$target` or any node its `$ref` chain leads to was visited, so a
     * component that only aliases a file the operations reference directly
     * still counts as referenced.
     *
     * @param array{0: string, 1: list<string>} $target
     * @param array<string, true> $visited
     */
    private function aliasReached(array $target, array $visited): bool
    {
        $seen = [];
        while ($target !== null && !isset($seen[$key = self::key(...$target)])) {
            if (isset($visited[$key])) {
                return true;
            }
            $seen[$key] = true;
            $node = $this->node(...$target);
            $target = is_array($node) && is_string($node['$ref'] ?? null) ? $this->target($node['$ref'], $target[0]) : null;
        }

        return false;
    }

    /** @param list<string> $segments */
    private function node(string $document, array $segments): mixed
    {
        $node = $this->documents[$document] ?? null;
        foreach ($segments as $segment) {
            if (!is_array($node) || !array_key_exists($segment, $node)) {
                return null;
            }
            $node = $node[$segment];
        }

        return $node;
    }

    /**
     * Queue the target of every `$ref` in `$node`, skipping the opaque values
     * the resolver does not descend into (`examples` holds no schema).
     *
     * @param array<mixed> $node
     * @param list<array{0: string, 1: list<string>}> $queue
     */
    private function collectRefs(array $node, string $document, bool $insideUserNamedMap, array &$queue): void
    {
        if (!$insideUserNamedMap && is_string($node['$ref'] ?? null)) {
            $queue[] = $this->target($node['$ref'], $document);
        }
        foreach ($node as $key => $child) {
            if (!is_array($child) || (!$insideUserNamedMap && in_array($key, self::OPAQUE_KEYS, true))) {
                continue;
            }
            $this->collectRefs($child, $document, !$insideUserNamedMap && in_array($key, OpenApiRefResolver::USER_NAMED_MAP_KEYS, true), $queue);
        }
    }

    /** @return array{0: string, 1: list<string>} */
    private function target(string $ref, string $document): array
    {
        [$path, $fragment] = explode('#', $ref, 2) + [1 => ''];
        if (str_starts_with($ref, 'http://') || str_starts_with($ref, 'https://')) {
            // Relative refs inside a remote document resolve against its URL,
            // so it cannot lead back to a local component: no need to fetch it.
            return [$path, self::segments($fragment)];
        }
        if ($path !== '') {
            $loaded = ExternalRefLoader::loadDocument($path, $document, $this->cache, $this->allowedLocalRefRoots);
            $document = $loaded->canonicalIdentifier;
            $this->documents[$document] ??= $loaded->decoded;
        }

        return [$document, self::segments($fragment)];
    }
}
