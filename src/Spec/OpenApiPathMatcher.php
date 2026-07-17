<?php

declare(strict_types=1);

namespace Studio\Gesso\Spec;

use InvalidArgumentException;

use function count;
use function explode;
use function implode;
use function in_array;
use function preg_match;
use function preg_quote;
use function rtrim;
use function sprintf;
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

            foreach ($segments as $segment) {
                if (preg_match('/^\{(.+)\}$/', $segment, $m)) {
                    // OpenAPI forbids duplicate placeholder names in a single template.
                    // If we silently overwrote the earlier capture, one of the segments
                    // would skip validation depending on position — a direction-dependent
                    // silent pass. Refuse to compile instead.
                    if (in_array($m[1], $paramNames, true)) {
                        throw new InvalidArgumentException(sprintf(
                            "Duplicate path placeholder name '%s' in spec path '%s'. OpenAPI requires unique placeholder names within a single template.",
                            $m[1],
                            $specPath,
                        ));
                    }

                    $compiledSegments[] = ['parameter' => $m[1]];
                    $paramNames[] = $m[1];
                    $patternBytes += 8; // slash plus `([^/]+)`
                } else {
                    $compiledSegments[] = ['literal' => $segment];
                    $literalCount++;
                    $patternBytes += strlen(preg_quote($segment, '#')) + 1;
                }
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
                'parameterCount' => $parameterCount,
                'patternBytes' => $patternBytes,
            ];
        }

        // Sort by literal segment count descending so more specific paths match first
        usort($compiled, static fn(array $a, array $b): int => $b['literalSegments'] <=> $a['literalSegments']);

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
                        if (isset($segment['literal'])) {
                            $regexSegments[] = preg_quote($segment['literal'], '#');

                            continue;
                        }

                        $regexSegments[] = '([^/]+)';
                        $parameters[$segment['parameter']] = $captureIndex++;
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

        // Keep the root path intact — collapsing `/` to `` would make the
        // single literal-root entry unreachable.
        if ($normalizedPath !== '/' && str_ends_with($normalizedPath, '/')) {
            $normalizedPath = rtrim($normalizedPath, '/');
        }

        return ['path' => $normalizedPath, 'strippedPrefix' => $strippedPrefix];
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
        $normalizedPath = $this->normalizeRequestPath($requestPath)['path'];

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

    private static function segmentCount(string $path): int
    {
        return substr_count(trim($path, '/'), '/') + 1;
    }
}
