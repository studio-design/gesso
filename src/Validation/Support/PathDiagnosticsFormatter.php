<?php

declare(strict_types=1);

namespace Studio\Gesso\Validation\Support;

use const PHP_URL_PATH;

use Studio\Gesso\Spec\OpenApiPathMatcher;
use Studio\Gesso\Spec\OpenApiPathSuggester;

use function implode;
use function is_array;
use function is_string;
use function parse_url;
use function rtrim;
use function str_starts_with;
use function strlen;
use function strtoupper;
use function substr;

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
 * - The "servers[n].url declares base path" callout fires only when removing a
 *   root-declared server base path turns the unmatched path into a matching
 *   one. See {@see self::serverBasePathHint()} and
 *   [ADR 0006](../../../docs/adr/0006-server-base-paths-and-request-path-matching.md).
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

        $serverHint = self::serverBasePathHint($spec, $normalized['path'], $matcher);
        if ($serverHint !== null) {
            $lines[] = "  servers[{$serverHint['index']}].url declares base path '{$serverHint['prefix']}'; '{$serverHint['stripped']}' matches after removing it.";
            $lines[] = "  Gesso does not strip server base paths automatically — add '{$serverHint['prefix']}' to strip_prefixes.";
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
     * The root-declared server base path that would have turned this unmatched
     * request path into a match, or null when none would.
     *
     * Root `servers` only. The array is overridable per Path Item and per
     * Operation, but reading an override means having matched the path first —
     * which is what the base path was needed for. ADR 0006 decides not to close
     * that circularity; here it only bounds how often the hint can fire. The
     * hint never claims a match it has not confirmed against the matcher.
     *
     * Entries are scanned in declaration order and the first that matches wins,
     * mirroring {@see OpenApiPathMatcher::normalizeRequestPath()}'s own rule for
     * `strip_prefixes`. A URL carrying a host contributes its path component
     * only; a path left holding unresolved `{variable}` segments cannot prefix
     * a real request path, so server variables need no special case.
     *
     * @param array<string, mixed> $spec
     *
     * @return null|array{index: int, prefix: string, stripped: string}
     */
    private static function serverBasePathHint(
        array $spec,
        string $normalizedPath,
        OpenApiPathMatcher $matcher,
    ): ?array {
        $servers = $spec['servers'] ?? null;
        if (!is_array($servers)) {
            return null;
        }

        $index = -1;
        foreach ($servers as $server) {
            $index++;
            $url = is_array($server) ? $server['url'] ?? null : null;
            if (!is_string($url)) {
                continue;
            }

            $prefix = rtrim((string) parse_url($url, PHP_URL_PATH), '/');
            if ($prefix === '' || !str_starts_with($prefix, '/') || !str_starts_with($normalizedPath, $prefix)) {
                continue;
            }

            $stripped = substr($normalizedPath, strlen($prefix));
            if ($stripped === '' || $matcher->match($stripped) === null) {
                continue;
            }

            return ['index' => $index, 'prefix' => $prefix, 'stripped' => $stripped];
        }

        return null;
    }
}
