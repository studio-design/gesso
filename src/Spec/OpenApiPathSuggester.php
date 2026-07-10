<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Spec;

use function abs;
use function array_map;
use function array_slice;
use function count;
use function explode;
use function implode;
use function is_array;
use function levenshtein;
use function min;
use function sort;
use function trim;
use function usort;

/**
 * Diagnostic helper that proposes "did you mean?" suggestions when a request
 * path fails to match any spec entry. Pure static utility: it inspects the
 * decoded spec array (already resolved by OpenApiSpecResolver) and never
 * touches network or filesystem.
 *
 * Kept separate from OpenApiPathMatcher so the matcher's hot-path API stays
 * focused on matching. Suggestion is only invoked from error paths.
 *
 * @internal Not part of the package's public API. Do not use from user code.
 */
final class OpenApiPathSuggester
{
    /**
     * @param array<string, mixed> $spec the decoded OpenAPI document
     * @param string $normalizedPath the request path *after* prefix stripping
     *                               (i.e. what the matcher actually compared
     *                               against). Suggestions ranked against this
     *                               value, not the raw request URI.
     * @param int $limit maximum number of (method, path) entries to return
     *
     * @return list<array{method: string, path: string}> top-$limit candidates
     *                                                   sorted by similarity descending. Empty when the spec has no
     *                                                   paths at all.
     */
    public static function suggest(array $spec, string $normalizedPath, int $limit = 3): array
    {
        // PHP's array_slice would interpret a negative $limit as "count from
        // the end", producing a surprising non-empty result for what callers
        // intend as "no suggestions". Treat zero / negative as the obvious
        // semantic: nothing requested ⇒ nothing returned.
        if ($limit < 1) {
            return [];
        }

        // A malformed spec where `paths` is null / scalar / list (rather than a
        // string-keyed map) would otherwise warn on `foreach`. The diagnostic
        // helper must never compound a primary failure with a secondary
        // TypeError or warning the user can't act on — bail out quietly.
        $paths = $spec['paths'] ?? [];
        if (!is_array($paths) || $paths === []) {
            return [];
        }

        /** @var array<string, mixed> $paths */
        $requestSegments = self::segments($normalizedPath);
        $entries = [];

        foreach ($paths as $specPath => $pathItem) {
            // Defensive: an unresolved $ref or malformed entry would otherwise
            // tip a TypeError into the diagnostic path. Suggestions are
            // best-effort — silently skipping malformed entries keeps the
            // primary error message intact.
            if (!is_array($pathItem)) {
                continue;
            }

            $specSegments = self::segments((string) $specPath);
            $segmentDiff = abs(count($requestSegments) - count($specSegments));
            $commonPrefix = self::commonPrefixSegmentCount($requestSegments, $specSegments);
            $tailDistance = self::levenshteinTail($requestSegments, $specSegments, $commonPrefix);

            foreach (OpenApiOperationResolver::declaredOperations($pathItem) as $declared) {
                if (!is_array($declared['operation'])) {
                    continue;
                }

                $entries[] = [
                    'method' => $declared['method'],
                    'path' => (string) $specPath,
                    'segmentDiff' => $segmentDiff,
                    'commonPrefix' => $commonPrefix,
                    'tailDistance' => $tailDistance,
                ];
            }
        }

        usort($entries, static function (array $a, array $b): int {
            // Tier ordering chosen so that the strongest signals (segment-count
            // match, then how many leading segments line up exactly) outrank
            // raw character-level edit distance. Levenshtein on the tail is the
            // tiebreaker for paths that are otherwise indistinguishable; a full
            // path Levenshtein would be dominated by the shared prefix and
            // surface noisy candidates.
            return $a['segmentDiff'] <=> $b['segmentDiff']
                ?: $b['commonPrefix'] <=> $a['commonPrefix']
                ?: $a['tailDistance'] <=> $b['tailDistance']
                ?: $a['path'] <=> $b['path']
                ?: $a['method'] <=> $b['method'];
        });

        $top = array_slice($entries, 0, $limit);

        // Strip scoring fields before returning so the public shape is a clean
        // {method, path} record.
        return array_map(
            static fn(array $e): array => ['method' => $e['method'], 'path' => $e['path']],
            $top,
        );
    }

    /**
     * Enumerate the operation methods declared under a given path item.
     * Fixed fields are uppercased while additional operation methods retain
     * the exact capitalization declared by the spec. Results are sorted, with
     * non-method keys (`parameters`, `summary`, `$ref`, …) filtered out.
     * Returns `[]` for an unknown path or a path-item that only declares shared
     * parameters — the caller distinguishes the two via prior matching.
     *
     * @param array<string, mixed> $spec
     *
     * @return list<string>
     */
    public static function methodsForPath(array $spec, string $matchedPath): array
    {
        $paths = $spec['paths'] ?? null;
        if (!is_array($paths)) {
            return [];
        }

        $pathItem = $paths[$matchedPath] ?? null;
        if (!is_array($pathItem)) {
            return [];
        }

        $methods = [];
        foreach (OpenApiOperationResolver::declaredOperations($pathItem) as $declared) {
            if (!is_array($declared['operation'])) {
                continue;
            }
            $methods[] = $declared['method'];
        }

        sort($methods);

        return $methods;
    }

    /**
     * @return list<string>
     */
    private static function segments(string $path): array
    {
        $trimmed = trim($path, '/');
        if ($trimmed === '') {
            return [];
        }

        return explode('/', $trimmed);
    }

    /**
     * @param list<string> $a
     * @param list<string> $b
     */
    private static function commonPrefixSegmentCount(array $a, array $b): int
    {
        $shared = 0;
        $bound = min(count($a), count($b));
        for ($i = 0; $i < $bound; $i++) {
            if ($a[$i] !== $b[$i]) {
                break;
            }
            $shared++;
        }

        return $shared;
    }

    /**
     * Levenshtein distance over the segments *after* the longest common
     * prefix, joined back into a `/`-delimited string. Ignoring the shared
     * prefix prevents long identical heads from dwarfing the actual typo
     * signal in the differing tail.
     *
     * @param list<string> $request
     * @param list<string> $spec
     */
    private static function levenshteinTail(array $request, array $spec, int $commonPrefix): int
    {
        $requestTail = implode('/', array_slice($request, $commonPrefix));
        $specTail = implode('/', array_slice($spec, $commonPrefix));

        return levenshtein($requestTail, $specTail);
    }
}
