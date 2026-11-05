<?php

declare(strict_types=1);

namespace Studio\Gesso\Spec;

use const PHP_URL_PATH;

use function array_key_exists;
use function in_array;
use function is_array;
use function is_int;
use function is_string;
use function parse_url;
use function rtrim;
use function str_contains;
use function str_starts_with;
use function strlen;
use function strtr;
use function uksort;

/**
 * The base paths a document's `servers` declare, and the path templates each
 * one serves (ADR 0008).
 *
 * An operation's servers are its own `servers`, else its Path Item's, else
 * the root's: each level replaces the one above rather than extending it. A
 * template is served under a base path when any of its operations is. A URL
 * carrying a host contributes its path component only; a URL whose path is
 * empty or `/` declares no base path.
 *
 * @internal Not part of the package's public API. Do not use from user code.
 */
final class OpenApiServerBasePaths
{
    /**
     * @param array<array-key, mixed> $spec
     *
     * @return array<string, list<string>> base path => the templates it serves (possibly none), longest base path first
     */
    public static function fromSpec(array $spec): array
    {
        $paths = $spec['paths'] ?? null;
        if (!is_array($paths)) {
            return [];
        }
        $root = $spec['servers'] ?? null;

        // A root base path that no template is served under still counts, so
        // a request under it can be told why it did not match.
        $basePaths = [];
        foreach (is_array($root) ? $root : [] as $server) {
            $basePath = self::basePath($server);
            if ($basePath !== null) {
                $basePaths[$basePath] ??= [];
            }
        }
        foreach ($paths as $template => $pathItem) {
            if (!is_string($template) || !is_array($pathItem)) {
                continue;
            }
            $pathServers = array_key_exists('servers', $pathItem) ? $pathItem['servers'] : $root;

            $serverLists = [];
            foreach (OpenApiOperationResolver::declaredOperations($pathItem) as $declared) {
                if (is_array($declared['operation'])) {
                    $serverLists[] = array_key_exists('servers', $declared['operation']) ? $declared['operation']['servers'] : $pathServers;
                }
            }
            if ($serverLists === []) {
                $serverLists[] = $pathServers;
            }

            foreach ($serverLists as $servers) {
                foreach (is_array($servers) ? $servers : [] as $server) {
                    $basePath = self::basePath($server);
                    if ($basePath !== null && !in_array($template, $basePaths[$basePath] ?? [], true)) {
                        $basePaths[$basePath][] = $template;
                    }
                }
            }
        }
        uksort($basePaths, static fn(string $a, string $b): int => strlen($b) <=> strlen($a) ?: $a <=> $b);

        return $basePaths;
    }

    /**
     * The base path of one Server Object, or null when it declares none.
     *
     * `{variable}`s are read with their `default`, which is REQUIRED on a
     * Server Variable Object and "SHALL be sent if an alternate value is not
     * supplied" (OpenAPI 3.0.4 through 3.2.0). A variable without a usable
     * default stays in braces, so it cannot prefix a real request path.
     */
    private static function basePath(mixed $server): ?string
    {
        if (!is_array($server) || !is_string($server['url'] ?? null)) {
            return null;
        }
        $url = $server['url'];
        if (str_contains($url, '{') && is_array($server['variables'] ?? null)) {
            $replacements = [];
            foreach ($server['variables'] as $name => $variable) {
                $default = is_array($variable) ? ($variable['default'] ?? null) : null;
                if (is_string($name) && (is_string($default) || is_int($default))) {
                    $replacements['{' . $name . '}'] = (string) $default;
                }
            }
            $url = strtr($url, $replacements);
        }
        $path = parse_url($url, PHP_URL_PATH);
        $path = is_string($path) ? rtrim($path, '/') : '';

        return $path !== '' && str_starts_with($path, '/') && !str_contains($path, '{') ? $path : null;
    }
}
