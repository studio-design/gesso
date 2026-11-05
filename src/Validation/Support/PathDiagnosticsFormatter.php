<?php

declare(strict_types=1);

namespace Studio\Gesso\Validation\Support;

use Studio\Gesso\Spec\OpenApiPathMatcher;
use Studio\Gesso\Spec\OpenApiPathSuggester;

use function array_filter;
use function array_push;
use function array_values;
use function count;
use function implode;
use function strtoupper;

/**
 * Composes the multi-line "no matching path" / single-line "method not
 * defined" diagnostics shared by {@see OpenApiResponseValidator} and
 * {@see OpenApiRequestValidator}. Lives alongside {@see ValidatorErrorBoundary}
 * because both serve the same purpose: keep cross-validator error wording in
 * one place so it cannot drift between request- and response-side surfaces.
 *
 * Formatting decisions worth knowing for callers:
 * - The "searched as:" callout fires only when {@see OpenApiPathMatcher::normalizeRequestPath()}
 *   reports a non-null `strippedPrefix`. Trailing-slash trimming alone is not
 *   surfaced — it's a universal normalization and adding a line for it would
 *   dilute the more useful prefix signal.
 * - The server base path callouts fire only when no configured prefix
 *   matched, so they are mutually exclusive with the "searched as:" callout
 *   above. See {@see self::serverBasePathLines()} and
 *   [ADR 0008](../../../docs/adr/0008-server-base-paths-in-path-matching.md).
 * - The "closest spec paths:" section is omitted entirely when the suggester
 *   produces no candidates (empty / malformed spec).
 * - The "Defined methods:" suffix renders `(none)` when a path item declares
 *   only non-operation keys. This is reachable in practice when the spec has
 *   a path-item with shared `parameters` / `summary` but no operations
 *   declared yet (a legal OpenAPI 3.x construction) — the validator will
 *   route to this helper because the requested method is missing AND every
 *   other method is too. Rendering an explicit `(none)` keeps that case
 *   visible instead of producing "Defined methods: .".
 *
 * @internal Not part of the package's public API. Do not use from user code.
 */
final class PathDiagnosticsFormatter
{
    /**
     * @param array<string, mixed> $spec the decoded OpenAPI document, used to
     *                                   draw "did you mean?" candidates from
     */
    public static function pathNotFound(
        string $specName,
        string $method,
        string $requestPath,
        OpenApiPathMatcher $matcher,
        array $spec,
    ): string {
        $upperMethod = strtoupper($method);
        $normalized = $matcher->normalizeRequestPath($requestPath);
        $suggestions = OpenApiPathSuggester::suggest($spec, $normalized['path']);

        $lines = ["No matching path found in '{$specName}' spec for {$upperMethod} {$requestPath}"];

        if ($normalized['strippedPrefix'] !== null) {
            $lines[] = "  searched as: {$normalized['path']} (after stripping prefix '{$normalized['strippedPrefix']}')";
        }

        if ($normalized['strippedPrefix'] === null) {
            array_push($lines, ...self::serverBasePathLines($requestPath, $matcher));
        }

        if ($suggestions !== []) {
            $lines[] = '  closest spec paths:';
            foreach ($suggestions as $s) {
                $lines[] = "    - {$s['method']} {$s['path']}";
            }
        }

        return implode("\n", $lines);
    }

    /**
     * @param array<string, mixed> $spec
     */
    public static function methodNotDefined(
        string $specName,
        string $method,
        string $matchedPath,
        array $spec,
    ): string {
        $methods = OpenApiPathSuggester::methodsForPath($spec, $matchedPath);
        $defined = $methods === [] ? '(none)' : implode(', ', $methods);

        return "Method {$method} not defined for path {$matchedPath} in '{$specName}' spec. Defined methods: {$defined}.";
    }

    /**
     * Why the spec's server base paths did not produce a match (ADR 0008).
     *
     * While `strip_prefixes` is set, base paths are not applied, so the
     * first one whose removal matches is named as the value to add. Otherwise
     * the request failed with them applied, so each candidate says why it was
     * rejected: its template's servers do not declare it, or more than one
     * base path matched a different template.
     *
     * @return list<string>
     */
    private static function serverBasePathLines(string $requestPath, OpenApiPathMatcher $matcher): array
    {
        $candidates = $matcher->serverBasePathCandidates($requestPath);
        if ($candidates === []) {
            return [];
        }
        if (!$matcher->appliesServerBasePaths()) {
            // `strip_prefixes` turns an exact match into the empty path, which
            // matches nothing, so that candidate cannot be advertised.
            $candidates = array_values(array_filter($candidates, static fn(array $candidate): bool => $candidate['prefix'] !== $requestPath));
            if ($candidates === []) {
                return [];
            }
            $candidate = $candidates[0];

            return [
                "  the spec's servers declare base path '{$candidate['prefix']}'; {$candidate['match']['path']} matches after removing it.",
                "  Server base paths are not applied while spec.strip_prefixes is set — add '{$candidate['prefix']}' to it in gesso.php.",
            ];
        }

        $lines = [];
        $served = [];
        foreach ($candidates as $candidate) {
            if ($candidate['served']) {
                $served[] = "'{$candidate['prefix']}' → {$candidate['match']['path']}";
            } else {
                $lines[] = "  {$candidate['match']['path']} matches after removing server base path '{$candidate['prefix']}', but the servers of {$candidate['match']['path']} do not declare it.";
            }
        }
        if (count($served) > 1) {
            $lines[] = '  server base paths match more than one spec path: ' . implode(', ', $served) . '. Set spec.strip_prefixes to choose one.';
        }

        return $lines;
    }
}
