<?php

declare(strict_types=1);

namespace Studio\Gesso\Spec;

use const PREG_SPLIT_DELIM_CAPTURE;

use InvalidArgumentException;

use function count;
use function explode;
use function implode;
use function in_array;
use function preg_match;
use function preg_quote;
use function preg_split;
use function rtrim;
use function sprintf;
use function str_contains;
use function str_ends_with;
use function str_starts_with;
use function strlen;
use function substr;
use function substr_count;
use function trim;
use function usort;

/**
 * @internal Not part of the package's public API. Do not use from user code.
 */
final class OpenApiPathMatcher
{
    /** Keep each combined PCRE bounded for specs with thousands of templates. */
    private const MAX_TEMPLATES_PER_PATTERN = 32;

    /** Keep JIT patterns at the largest capture count exercised safely by the legacy matcher. */
    private const MAX_CAPTURES_PER_JIT_PATTERN = 400;

    /** Leave headroom below PCRE's compiled-pattern size ceiling. */
    private const MAX_COMBINED_PATTERN_BYTES = 30_000;

    /** Reject one template that cannot be split below PCRE's named-capture ceiling. */
    private const MAX_PARAMETERS_PER_TEMPLATE = 10_000;

    /** @var array<string, string> normalized request path => original spec path */
    private array $literalPaths;

    /**
     * @var array<int, list<array{
     *     pattern: string,
     *     matches: array<int, array{path: string, parameters: array<string, int>}>,
     * }>>
     */
    private array $compiledPathsBySegmentCount;

    /**
     * @param string[] $specPaths
     * @param string[] $stripPrefixes Prefixes to strip from request paths before matching (e.g., ['/api'])
     */
    public function __construct(
        array $specPaths,
        private readonly array $stripPrefixes = [],
    ) {
        $compiled = [];
        $literalPaths = [];
        foreach ($specPaths as $specPath) {
            $segments = explode('/', trim($specPath, '/'));
            $literalCount = 0;
            $compiledSegments = [];
            $paramNames = [];
            $patternBytes = 16; // anchors, non-capturing wrapper, and MARK

            $mixedLiteralBytes = 0;

            foreach ($segments as $segment) {
                // OpenAPI's path-segment grammar is `1*( path-literal / template-expression )`,
                // so one segment may mix literals and expressions (`report.{format}`).
                // Even indexes are literals (possibly empty); odd indexes are names.
                $pieces = str_contains($segment, '{')
                    ? preg_split('/\{([^{}]+)\}/', $segment, -1, PREG_SPLIT_DELIM_CAPTURE)
                    : false;
                if ($pieces === false || count($pieces) === 1) {
                    $compiledSegments[] = ['regex' => preg_quote($segment, '#'), 'parameters' => []];
                    $literalCount++;
                    $patternBytes += strlen(preg_quote($segment, '#')) + 1;

                    continue;
                }

                $regex = '';
                $segmentParameters = [];
                $lastIndex = count($pieces) - 1;
                foreach ($pieces as $index => $piece) {
                    if ($index % 2 === 0) {
                        if ($piece !== '') {
                            $regex .= preg_quote($piece, '#');
                            $mixedLiteralBytes += strlen($piece);
                        } elseif ($index > 0 && $index < $lastIndex) {
                            // The grammar allows `{a}{b}`, but nothing decides where
                            // one value ends and the next begins. OpenAPI leaves
                            // ambiguous matching to tooling; refuse to guess a split.
                            throw new InvalidArgumentException(sprintf(
                                "Adjacent path placeholders with no literal between them in spec path '%s'. The boundary between their values is ambiguous.",
                                $specPath,
                            ));
                        }

                        continue;
                    }

                    // OpenAPI forbids duplicate placeholder names in a single template.
                    // If we silently overwrote the earlier capture, one of the segments
                    // would skip validation depending on position — a direction-dependent
                    // silent pass. Refuse to compile instead.
                    if (in_array($piece, $paramNames, true)) {
                        throw new InvalidArgumentException(sprintf(
                            "Duplicate path placeholder name '%s' in spec path '%s'. OpenAPI requires unique placeholder names within a single template.",
                            $piece,
                            $specPath,
                        ));
                    }

                    // A value ends at the first occurrence of the literal that
                    // follows it, as in Symfony/Laravel routing: `{name}.{ext}`
                    // splits `a.tar.gz` into `a` and `tar.gz`. The possessive
                    // quantifier keeps matching linear — unbounded backtracking
                    // across several captures could exhaust PCRE's limit and
                    // hide a fallback template in the same combined pattern.
                    $next = $pieces[$index + 1];
                    $regex .= $next === ''
                        ? '([^/]+)'
                        : '((?:(?!' . preg_quote($next, '#') . ')[^/])++)';
                    $segmentParameters[] = $piece;
                    $paramNames[] = $piece;
                }
                $compiledSegments[] = ['regex' => $regex, 'parameters' => $segmentParameters];
                $patternBytes += strlen($regex) + 1;
            }

            $parameterCount = count($paramNames);
            if ($parameterCount > self::MAX_PARAMETERS_PER_TEMPLATE) {
                throw new InvalidArgumentException(sprintf(
                    "Spec path '%s' declares %d placeholders; a single template may declare at most %d.",
                    $specPath,
                    $parameterCount,
                    self::MAX_PARAMETERS_PER_TEMPLATE,
                ));
            }

            if ($paramNames === []) {
                // Requests are normalized by removing trailing slashes before
                // matching. Preserve the first declaration when two literal
                // spec paths normalize to the same request path, matching the
                // stable ordering of the previous compiled-regex scan.
                $literalPath = '/' . implode('/', $segments);
                if (!isset($literalPaths[$literalPath])) {
                    $literalPaths[$literalPath] = $specPath;
                }

                continue;
            }

            $compiled[] = [
                'segments' => $compiledSegments,
                'path' => $specPath,
                'literalSegments' => $literalCount,
                'mixedLiteralBytes' => $mixedLiteralBytes,
                'parameterCount' => $parameterCount,
                'patternBytes' => $patternBytes,
            ];
        }

        // More specific paths match first: whole literal segments, then literal
        // bytes inside mixed segments (`/report.{format}` before `/{file}`).
        // usort is stable, so otherwise-equal templates keep declaration order.
        usort($compiled, static fn(array $a, array $b): int => [$b['literalSegments'], $b['mixedLiteralBytes']] <=> [$a['literalSegments'], $a['mixedLiteralBytes']]);

        $pathsBySegmentCount = [];
        foreach ($compiled as $path) {
            $segmentCount = self::segmentCount($path['path']);
            $pathsBySegmentCount[$segmentCount][] = $path;
        }

        $compiledPathsBySegmentCount = [];
        foreach ($pathsBySegmentCount as $segmentCount => $paths) {
            $chunks = [];
            $chunk = [];
            $chunkCaptureCount = 0;
            $chunkPatternBytes = 0;
            foreach ($paths as $path) {
                if ($chunk !== [] && (
                    count($chunk) >= self::MAX_TEMPLATES_PER_PATTERN ||
                    $chunkCaptureCount + $path['parameterCount'] > self::MAX_CAPTURES_PER_JIT_PATTERN ||
                    $chunkPatternBytes + $path['patternBytes'] > self::MAX_COMBINED_PATTERN_BYTES
                )) {
                    $chunks[] = $chunk;
                    $chunk = [];
                    $chunkCaptureCount = 0;
                    $chunkPatternBytes = 0;
                }

                // A single long-literal template can exceed the combination
                // byte budget but still compile on its own, as in the legacy
                // one-pattern-per-template implementation.
                $chunk[] = $path;
                $chunkCaptureCount += $path['parameterCount'];
                $chunkPatternBytes += $path['patternBytes'];
            }
            $chunks[] = $chunk;

            foreach ($chunks as $chunk) {
                $alternatives = [];
                $matches = [];
                $captureIndex = 1;
                foreach ($chunk as $pathIndex => $path) {
                    $regexSegments = [];
                    $parameters = [];
                    foreach ($path['segments'] as $segment) {
                        $regexSegments[] = $segment['regex'];
                        foreach ($segment['parameters'] as $parameter) {
                            $parameters[$parameter] = $captureIndex++;
                        }
                    }

                    $marker = (string) $pathIndex;
                    $alternatives[] = '/' . implode('/', $regexSegments) . sprintf('(*MARK:%s)', $marker);
                    $matches[$marker] = ['path' => $path['path'], 'parameters' => $parameters];
                }

                $jitControl = $captureIndex - 1 > self::MAX_CAPTURES_PER_JIT_PATTERN
                    ? '(*NO_JIT)'
                    : '';
                $compiledPathsBySegmentCount[$segmentCount][] = [
                    'pattern' => '#' . $jitControl . '^(?:' . implode('|', $alternatives) . ')$#',
                    'matches' => $matches,
                ];
            }
        }

        $this->literalPaths = $literalPaths;
        $this->compiledPathsBySegmentCount = $compiledPathsBySegmentCount;
    }

    public function match(string $requestPath): ?string
    {
        return $this->matchWithVariables($requestPath)['path'] ?? null;
    }

    /**
     * Apply the matcher's prefix-stripping and trailing-slash policy to a raw
     * request URI without attempting to match it against the spec. Exposed so
     * diagnostic code (e.g. "did you mean?" suggestions) can show users the
     * exact string that the matcher actually compared against, distinct from
     * the raw URI they passed in.
     *
     * Contract specifics callers should know:
     * - **First match wins.** `$stripPrefixes` is iterated in construction
     *   order; once one matches, the loop breaks. Subsequent prefixes are not
     *   considered even if they would also match.
     * - **Prefix matching is literal `str_starts_with`, not segment-aware.**
     *   A configured prefix `/api` will also strip a request path of
     *   `/api2/foo` (yielding `2/foo`). Configure prefixes with explicit
     *   trailing slashes or include a path separator (`/api/`) if segment
     *   semantics matter.
     * - `strippedPrefix` is the literal prefix value that was removed
     *   (verbatim from the constructor's `$stripPrefixes`), or null when no
     *   configured prefix matched.
     * - Trailing-slash trimming is applied unconditionally after prefix
     *   stripping but does not surface as a separate signal — its effect on
     *   diagnostic messages is judged not worth a second field.
     *
     * @return array{path: string, strippedPrefix: ?string}
     */
    public function normalizeRequestPath(string $requestPath): array
    {
        $normalizedPath = $requestPath;
        $strippedPrefix = null;

        foreach ($this->stripPrefixes as $prefix) {
            if (str_starts_with($normalizedPath, $prefix)) {
                $normalizedPath = substr($normalizedPath, strlen($prefix));
                $strippedPrefix = $prefix;

                break;
            }
        }

        return ['path' => self::trimTrailingSlash($normalizedPath), 'strippedPrefix' => $strippedPrefix];
    }

    /**
     * Match a request path from which a prefix has *already* been removed,
     * applying the rest of the normalization policy (trailing-slash trimming)
     * but not `$stripPrefixes` again.
     *
     * Exposed for diagnostics that simulate a prefix the user has not
     * configured yet. A real request loses at most one prefix — the loop in
     * {@see self::normalizeRequestPath()} breaks on the first match — so a
     * simulation that re-entered {@see self::match()} would strip a second
     * time and could report a match that no `strip_prefixes` value reproduces.
     */
    public function matchWithoutPrefixStripping(string $strippedPath): ?string
    {
        return $this->lookup(self::trimTrailingSlash($strippedPath))['path'] ?? null;
    }

    /**
     * Match a request path and return both the matched spec path template and
     * the raw values captured for each `{placeholder}` segment.
     *
     * Values are returned exactly as they appeared in `$requestPath` — no
     * decoding is performed. When the caller passes the raw request URI (the
     * intended use, e.g. via Symfony's `Request::getPathInfo()` which returns
     * the un-decoded path), the captured values will be percent-encoded and
     * the caller should apply `rawurldecode()` before validating. Keeping
     * encoding policy in one place on the caller side avoids the double-decode
     * hazard that would arise if we decoded here.
     *
     * @return null|array{path: string, variables: array<string, string>}
     */
    public function matchWithVariables(string $requestPath): ?array
    {
        return $this->lookup($this->normalizeRequestPath($requestPath)['path']);
    }

    /**
     * Keep the root path intact — collapsing `/` to `` would make the single
     * literal-root entry unreachable.
     */
    private static function trimTrailingSlash(string $path): string
    {
        return $path !== '/' && str_ends_with($path, '/') ? rtrim($path, '/') : $path;
    }

    private static function segmentCount(string $path): int
    {
        return substr_count(trim($path, '/'), '/') + 1;
    }

    /**
     * The spec-path lookup itself, on an already-normalized request path.
     *
     * @return null|array{path: string, variables: array<string, string>}
     */
    private function lookup(string $normalizedPath): ?array
    {
        if (isset($this->literalPaths[$normalizedPath])) {
            return ['path' => $this->literalPaths[$normalizedPath], 'variables' => []];
        }

        $compiledPatterns = $this->compiledPathsBySegmentCount[self::segmentCount($normalizedPath)] ?? [];
        foreach ($compiledPatterns as $compiled) {
            $captures = [];
            if (preg_match($compiled['pattern'], $normalizedPath, $captures) !== 1) {
                continue;
            }

            // Numeric-string array keys are stored as integers by PHP; PCRE's
            // MARK capture is the corresponding numeric string.
            $matched = $compiled['matches'][(int) $captures['MARK']];
            $variables = [];
            foreach ($matched['parameters'] as $name => $captureIndex) {
                $variables[$name] = $captures[$captureIndex];
            }

            return ['path' => $matched['path'], 'variables' => $variables];
        }

        return null;
    }
}
