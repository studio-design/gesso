<?php

declare(strict_types=1);

namespace Studio\Gesso\Spec;

use const DIRECTORY_SEPARATOR;
use const E_USER_WARNING;
use const JSON_THROW_ON_ERROR;

use InvalidArgumentException;
use JsonException;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Studio\Gesso\Exception\InvalidOpenApiSpecException;
use Studio\Gesso\Exception\InvalidOpenApiSpecReason;
use Studio\Gesso\Exception\SpecFileNotFoundException;
use Studio\Gesso\Internal\HttpRefLoader;
use Studio\Gesso\Internal\OpenApiDocumentShapeNormalizer;
use Studio\Gesso\Internal\SpecDocumentDecoder;
use Studio\Gesso\Internal\YamlAvailability;
use Studio\Gesso\OpenApiVersion;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

use function array_key_exists;
use function explode;
use function file_exists;
use function file_get_contents;
use function get_debug_type;
use function implode;
use function in_array;
use function is_array;
use function json_decode;
use function preg_match;
use function realpath;
use function rtrim;
use function sprintf;
use function str_contains;
use function str_ends_with;
use function str_replace;
use function str_starts_with;
use function strtolower;
use function trigger_error;
use function trim;

final class OpenApiSpecLoader
{
    public const DEFAULT_MAX_REMOTE_REF_BYTES = HttpRefLoader::DEFAULT_MAX_RESPONSE_BYTES;

    /**
     * Extensions are searched in the order listed; the first hit wins.
     * JSON is first for back-compat with the pre-existing bundle workflow.
     *
     * @var list<string>
     */
    private const SEARCH_EXTENSIONS = ['json', 'yaml', 'yml'];
    private static ?string $basePath = null;

    /**
     * Optional secondary base path used exclusively for resolving
     * `#[BoundToOpenApiEnum]` paths (issue #170). When `null`, those paths
     * fall back to {@see $basePath} so existing single-root projects
     * keep their previous behavior unchanged.
     */
    private static ?string $enumBasePath = null;

    /** @var string[] */
    private static array $stripPrefixes = [];

    /** @var array<string, array<string, mixed>> */
    private static array $cache = [];
    private static ?ClientInterface $httpClient = null;
    private static ?RequestFactoryInterface $requestFactory = null;
    private static bool $allowRemoteRefs = false;

    /** @var list<string> */
    private static array $allowedRemoteRefHosts = [];
    private static int $maxRemoteRefBytes = self::DEFAULT_MAX_REMOTE_REF_BYTES;

    /**
     * Configure the spec loader.
     *
     * Existing cached specs are evicted on every call so a config change
     * (especially flipping `$allowRemoteRefs`) takes effect on the next
     * `load()`. Without this, a previously cached spec resolved under
     * the old policy would silently keep serving.
     *
     * @param string[] $stripPrefixes
     * @param null|ClientInterface $httpClient PSR-18 client used to fetch HTTP(S) `$ref`
     *                                         targets. Required when `$allowRemoteRefs` is true.
     * @param null|RequestFactoryInterface $requestFactory PSR-17 request factory paired
     *                                                     with `$httpClient`. Required when `$allowRemoteRefs` is true.
     * @param bool $allowRemoteRefs Opt-in for HTTP(S) `$ref` resolution. Defaults to false:
     *                              every external HTTP(S) ref throws `RemoteRefDisallowed` so a
     *                              spec can never silently reach the network during tests.
     * @param null|string $enumBasePath Optional secondary base path used only when resolving
     *                                  `#[BoundToOpenApiEnum]` attribute paths (issue #170).
     *                                  Lets projects keep `spec_base_path` pointed at a
     *                                  bundled aggregate root (e.g. `openapi/bundled/`) while
     *                                  individual enum JSONs live elsewhere (e.g.
     *                                  `openapi/_shared/...`). When `null` the asserter falls
     *                                  back to `$basePath`, keeping single-root setups
     *                                  bit-for-bit identical to the pre-issue-#170 behavior.
     * @param list<string> $allowedRemoteRefHosts Exact hostnames or IP literals permitted when
     *                                            `$allowRemoteRefs` is true. Ports and URL paths
     *                                            are not accepted. Matching is case-insensitive.
     * @param int $maxRemoteRefBytes Maximum HTTP response-body bytes read for each remote
     *                               document. Must be positive; the default is 10 MiB.
     *
     * @throws InvalidArgumentException for any misconfigured pair: `$allowRemoteRefs` true
     *                                  without client/factory, OR client provided without
     *                                  `$allowRemoteRefs` true. Both are surfaced at
     *                                  configure-time so the error never sits silent.
     */
    public static function configure(
        string $basePath,
        array $stripPrefixes = [],
        ?ClientInterface $httpClient = null,
        ?RequestFactoryInterface $requestFactory = null,
        bool $allowRemoteRefs = false,
        ?string $enumBasePath = null,
        array $allowedRemoteRefHosts = [],
        int $maxRemoteRefBytes = self::DEFAULT_MAX_REMOTE_REF_BYTES,
    ): void {
        if ($allowRemoteRefs && ($httpClient === null || $requestFactory === null)) {
            throw new InvalidArgumentException(
                'OpenApiSpecLoader::configure(): allowRemoteRefs requires both $httpClient '
                . '(PSR-18 ClientInterface) and $requestFactory (PSR-17 RequestFactoryInterface).',
            );
        }

        if (!$allowRemoteRefs && $httpClient !== null) {
            // The user wired a client but forgot to flip the switch.
            // Warning-level signals (E_USER_WARNING) are too easy to
            // suppress (custom error handlers, error_reporting masks);
            // surface as a hard exception so the misconfiguration cannot
            // sit silent. Symmetric with the allowRemoteRefs-without-client
            // check above — both halves of the pairing are enforced.
            throw new InvalidArgumentException(
                'OpenApiSpecLoader::configure(): an HTTP client was provided but '
                . 'allowRemoteRefs is false. HTTP $refs would be rejected silently. '
                . 'Either pass allowRemoteRefs: true, or omit the client entirely.',
            );
        }

        if ($allowRemoteRefs && $allowedRemoteRefHosts === []) {
            throw new InvalidArgumentException(
                'OpenApiSpecLoader::configure(): allowRemoteRefs requires at least one exact host '
                . 'in $allowedRemoteRefHosts.',
            );
        }

        if (!$allowRemoteRefs && $allowedRemoteRefHosts !== []) {
            throw new InvalidArgumentException(
                'OpenApiSpecLoader::configure(): $allowedRemoteRefHosts was provided but '
                . 'allowRemoteRefs is false.',
            );
        }

        if ($maxRemoteRefBytes < 1) {
            throw new InvalidArgumentException(
                'OpenApiSpecLoader::configure(): $maxRemoteRefBytes must be greater than zero.',
            );
        }

        $allowedRemoteRefHosts = self::normalizeAllowedRemoteRefHosts($allowedRemoteRefHosts);

        if ($enumBasePath !== null && trim($enumBasePath) === '') {
            // Empty / whitespace-only `enumBasePath` would otherwise survive
            // rtrim() as the empty string and surface later as
            // `EnumBasePathNotFound: Configured enum_spec_base_path is not a
            // directory: ` — an unhelpful diagnostic that hides the real
            // misconfiguration. Reject at the API surface instead, matching
            // the allowRemoteRefs pairing checks above. Pass `null` to
            // intentionally disable the parameter.
            throw new InvalidArgumentException(
                'OpenApiSpecLoader::configure(): $enumBasePath is empty or whitespace-only. '
                . 'Pass a valid directory path, or null to fall back to spec_base_path.',
            );
        }

        self::$basePath = self::normalizeConfiguredBasePath($basePath);
        self::$enumBasePath = $enumBasePath !== null ? self::normalizeConfiguredBasePath($enumBasePath) : null;
        self::$stripPrefixes = $stripPrefixes;
        self::$httpClient = $httpClient;
        self::$requestFactory = $requestFactory;
        self::$allowRemoteRefs = $allowRemoteRefs;
        self::$allowedRemoteRefHosts = $allowedRemoteRefHosts;
        self::$maxRemoteRefBytes = $maxRemoteRefBytes;
        // A previous configure() call may have cached specs under a
        // different remote-refs policy. Evict so the next load() runs
        // with the new client/flag combination.
        self::$cache = [];
    }

    public static function getBasePath(): string
    {
        if (self::$basePath === null) {
            throw new InvalidOpenApiSpecException(
                InvalidOpenApiSpecReason::BasePathNotConfigured,
                'OpenApiSpecLoader base path not configured. '
                . 'Call OpenApiSpecLoader::configure() or set spec_base_path in PHPUnit extension parameters.',
            );
        }

        return self::$basePath;
    }

    /**
     * Secondary base path for `#[BoundToOpenApiEnum]` resolution (issue #170).
     *
     * Returns `null` when not configured — `EnumDriftAsserter` is expected
     * to fall back to {@see getBasePath()} in that case. The deliberate
     * non-throwing shape mirrors how `enum_spec_base_path` is opt-in at the
     * extension layer: absence is the documented default, not an error.
     */
    public static function getEnumBasePath(): ?string
    {
        return self::$enumBasePath;
    }

    /** @return string[] */
    public static function getStripPrefixes(): array
    {
        return self::$stripPrefixes;
    }

    /** @return array<string, mixed> */
    public static function load(string $specName): array
    {
        if (isset(self::$cache[$specName])) {
            return self::$cache[$specName];
        }

        ['path' => $path, 'extension' => $extension] = self::resolveSpecFile($specName);

        $decoded = match ($extension) {
            'json' => self::decodeJsonSpec($path, $specName),
            'yaml', 'yml' => self::decodeYamlSpec($path, $specName),
            default => throw new InvalidOpenApiSpecException(
                InvalidOpenApiSpecReason::UnsupportedExtension,
                "Unsupported spec extension: .{$extension}",
                specName: $specName,
            ),
        };

        // Version selection changes schema semantics, so reject an absent,
        // malformed, or unsupported `openapi` value before resolving refs or
        // running any endpoint assertions. This also makes PHPUnit extension
        // bootstrap fail while eagerly loading its configured specs.
        try {
            $version = OpenApiVersion::fromSpec($decoded);
            OpenApiSchemaDialect::fromSpec($decoded, $version);
        } catch (InvalidOpenApiSpecException $e) {
            throw $e->withSpecName($specName);
        }

        if ($version === OpenApiVersion::V3_2 && array_key_exists('$self', $decoded)) {
            trigger_error(
                sprintf(
                    "[OpenAPI 3.2 \$self] spec '%s' declares a \$self base URI, but this loader still resolves "
                    . 'relative references from the retrieved file path. Remove $self or pre-bundle the document '
                    . 'until $self-aware resolution is implemented.',
                    $specName,
                ),
                E_USER_WARNING,
            );
        }

        // Canonicalize the source path so the resolver's cycle detection
        // sees the same key for this file whether it's reached as the
        // entry spec or as the target of a relative ref from another
        // file. Without this, `basePath` containing `./` or symlinks
        // would yield two distinct chain keys for the same document.
        $canonicalPath = realpath($path);
        if ($canonicalPath === false) {
            // resolveSpecFile() proved the file existed; if realpath
            // fails now it's a races/permissions edge case. Fall back
            // to the raw path so the message still locates it for the
            // operator.
            $canonicalPath = $path;
        }

        try {
            $resolved = OpenApiDocumentShapeNormalizer::normalizeResolvedDocument(
                OpenApiRefResolver::resolve(
                    $decoded,
                    $canonicalPath,
                    self::$httpClient,
                    self::$requestFactory,
                    self::$allowRemoteRefs,
                    self::$allowedRemoteRefHosts,
                    self::$maxRemoteRefBytes,
                    [self::getBasePath()],
                ),
            );
        } catch (InvalidOpenApiSpecException $e) {
            // The resolver is stateless and therefore cannot know which spec
            // produced the throw. Re-wrap once so consumers (e.g. the coverage
            // extension) can surface the spec name in diagnostics without
            // having to correlate against the call site.
            throw $e->withSpecName($specName);
        }
        self::$cache[$specName] = $resolved;

        return $resolved;
    }

    /**
     * Clear only the cached specs, keeping basePath and stripPrefixes intact.
     *
     * @internal Used by the PHPUnit extension to free memory after coverage
     * is computed. Production code should not call this — clearing mid-run
     * forces a re-parse on the next {@see self::load()} call.
     */
    public static function clearCache(): void
    {
        self::$cache = [];
    }

    /**
     * Remove a single spec from the cache.
     *
     * @internal Test seam — production code never needs this.
     */
    public static function evict(string $specName): void
    {
        unset(self::$cache[$specName]);
    }

    /**
     * Reset all configuration and cached state. Intended for test isolation
     * between runs.
     *
     * @internal
     */
    public static function reset(): void
    {
        self::$basePath = null;
        self::$enumBasePath = null;
        self::$stripPrefixes = [];
        self::$cache = [];
        self::$httpClient = null;
        self::$requestFactory = null;
        self::$allowRemoteRefs = false;
        self::$allowedRemoteRefHosts = [];
        self::$maxRemoteRefBytes = self::DEFAULT_MAX_REMOTE_REF_BYTES;
        YamlAvailability::reset();
    }

    /**
     * @param list<string> $hosts
     *
     * @return list<string>
     */
    private static function normalizeAllowedRemoteRefHosts(array $hosts): array
    {
        $normalized = [];
        foreach ($hosts as $host) {
            $candidate = HttpRefLoader::normalizeHost($host);
            $isBracketedIpv6 = str_starts_with($candidate, '[') && str_ends_with($candidate, ']');
            if ($candidate === '' ||
                str_contains($candidate, '://') ||
                str_contains($candidate, '/') ||
                str_contains($candidate, '@') ||
                str_contains($candidate, '?') ||
                str_contains($candidate, '#') ||
                (str_contains($candidate, ':') && !$isBracketedIpv6)
            ) {
                throw new InvalidArgumentException(
                    sprintf(
                        'OpenApiSpecLoader::configure(): invalid remote-ref host `%s`; pass a host only, without scheme, port, path, or userinfo.',
                        $host,
                    ),
                );
            }

            if (!in_array($candidate, $normalized, true)) {
                $normalized[] = $candidate;
            }
        }

        return $normalized;
    }

    /** @return array{path: string, extension: string} */
    private static function resolveSpecFile(string $specName): array
    {
        $basePath = self::getBasePath();

        // Spec names may include trusted nested directories (for example,
        // `bundled/front`) but must never select a parent or an absolute
        // filesystem location. Reject these shapes before checking existence
        // so callers cannot use the exception category as an existence probe.
        $portableSpecName = str_replace('\\', '/', $specName);
        if (str_contains($portableSpecName, "\0") ||
            str_starts_with($portableSpecName, '/') ||
            preg_match('/^[A-Za-z]:\//', $portableSpecName) === 1 ||
            in_array('..', explode('/', $portableSpecName), true)
        ) {
            throw self::specFileNotFound($specName, $basePath);
        }

        foreach (self::SEARCH_EXTENSIONS as $extension) {
            $candidate = "{$basePath}/{$specName}.{$extension}";
            if (!file_exists($candidate)) {
                continue;
            }

            $canonicalCandidate = realpath($candidate);
            $canonicalBase = realpath($basePath);
            if ($canonicalCandidate === false ||
                $canonicalBase === false ||
                !self::isPathInsideRoot($canonicalCandidate, $canonicalBase)
            ) {
                throw self::specFileNotFound($specName, $basePath);
            }

            return ['path' => $canonicalCandidate, 'extension' => $extension];
        }

        throw self::specFileNotFound($specName, $basePath);
    }

    private static function isPathInsideRoot(string $path, string $root): bool
    {
        $root = rtrim($root, '/\\');
        if (DIRECTORY_SEPARATOR === '\\') {
            $path = strtolower($path);
            $root = strtolower($root);
        }

        return $path === $root || str_starts_with($path, $root . DIRECTORY_SEPARATOR);
    }

    private static function normalizeConfiguredBasePath(string $path): string
    {
        $trimmed = rtrim($path, '/');
        if ($path !== '' && $trimmed === '') {
            return '/';
        }
        if (preg_match('/^[A-Za-z]:$/', $trimmed) === 1 && str_ends_with($path, '/')) {
            return $trimmed . '/';
        }

        return $trimmed;
    }

    private static function specFileNotFound(string $specName, string $basePath): SpecFileNotFoundException
    {
        return new SpecFileNotFoundException(
            $specName,
            $basePath,
            sprintf(
                'OpenAPI bundled spec not found: %s/%s (tried extensions: %s). '
                . "Run 'cd openapi && npm run bundle' to generate a JSON bundle, "
                . 'or place a .yaml / .yml source file alongside.',
                $basePath,
                $specName,
                '.' . implode(', .', self::SEARCH_EXTENSIONS),
            ),
        );
    }

    /** @return array<string, mixed> */
    private static function decodeJsonSpec(string $path, string $specName): array
    {
        $content = file_get_contents($path);
        if ($content === false) {
            // I/O failure after resolveSpecFile() already confirmed the file
            // exists is practically always a permissions / concurrent-unlink
            // issue — treat the file as effectively missing.
            throw new SpecFileNotFoundException(
                $specName,
                self::getBasePath(),
                "Failed to read OpenAPI spec: {$path}",
            );
        }

        try {
            $decoded = SpecDocumentDecoder::normalizeObjectMaps(
                json_decode($content, false, 512, JSON_THROW_ON_ERROR),
            );
        } catch (JsonException $e) {
            throw new InvalidOpenApiSpecException(
                InvalidOpenApiSpecReason::MalformedJson,
                "Failed to parse JSON OpenAPI spec: {$path}. {$e->getMessage()}",
                specName: $specName,
                previous: $e,
            );
        }

        if (!is_array($decoded)) {
            throw new InvalidOpenApiSpecException(
                InvalidOpenApiSpecReason::NonMappingRoot,
                sprintf('JSON OpenAPI spec must decode to a mapping (got %s): %s', get_debug_type($decoded), $path),
                specName: $specName,
            );
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /** @return array<string, mixed> */
    private static function decodeYamlSpec(string $path, string $specName): array
    {
        if (!YamlAvailability::isAvailable()) {
            throw new InvalidOpenApiSpecException(
                InvalidOpenApiSpecReason::YamlLibraryMissing,
                'Loading YAML OpenAPI specs requires symfony/yaml. '
                . 'Install it via: composer require --dev symfony/yaml',
                specName: $specName,
            );
        }

        // Yaml::parseFile wraps its own I/O failures in ParseException, so a
        // single catch covers both syntax errors and file-read problems.
        try {
            $decoded = SpecDocumentDecoder::normalizeObjectMaps(
                Yaml::parseFile($path, Yaml::PARSE_OBJECT_FOR_MAP),
            );
        } catch (ParseException $e) {
            throw new InvalidOpenApiSpecException(
                InvalidOpenApiSpecReason::MalformedYaml,
                "Failed to parse YAML OpenAPI spec: {$path}. {$e->getMessage()}",
                specName: $specName,
                previous: $e,
            );
        }

        if (!is_array($decoded)) {
            throw new InvalidOpenApiSpecException(
                InvalidOpenApiSpecReason::NonMappingRoot,
                sprintf('YAML OpenAPI spec must decode to a mapping (got %s): %s', get_debug_type($decoded), $path),
                specName: $specName,
            );
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
