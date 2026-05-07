<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Internal;

use Composer\Autoload\ClassLoader;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionEnum;
use ReflectionException;
use SplFileInfo;
use Studio\OpenApiContractTesting\Attribute\BoundToOpenApiEnum;
use Studio\OpenApiContractTesting\Exception\EnumBindingException;
use Studio\OpenApiContractTesting\Exception\EnumBindingReason;

use function array_keys;
use function array_unique;
use function array_values;
use function enum_exists;
use function implode;
use function is_array;
use function is_dir;
use function ltrim;
use function rtrim;
use function sort;
use function spl_autoload_functions;
use function sprintf;
use function str_ends_with;
use function str_replace;
use function str_starts_with;
use function strlen;
use function substr;

/**
 * Discover backed PHP enums carrying `#[BoundToOpenApiEnum]` under one or
 * more PSR-4 namespace prefixes. Used by `OpenApiCoverageExtension` so users
 * no longer have to enumerate every bound enum manually.
 *
 * Discovery merges results from Composer's classmap (`getClassMap()`) and a
 * recursive scan of each PSR-4-registered directory, deduplicating across
 * both sources. Production deployments using `--optimize-autoloader` /
 * `--classmap-authoritative` are covered by the classmap pass; default dev
 * installs are covered by the PSR-4 directory walk.
 *
 * @internal Not part of the package's public API. Do not use from user code.
 */
final class EnumScanner
{
    private static ?ClassLoader $loaderOverride = null;
    private static bool $forceUnavailable = false;

    /** @var array<string, list<string>> */
    private static array $cache = [];

    private function __construct() {}

    /**
     * Return the FQCNs of every backed enum under one of `$namespacePrefixes`
     * that carries the `#[BoundToOpenApiEnum]` attribute. Result is sorted
     * and deduplicated.
     *
     * @param list<string> $namespacePrefixes
     *
     * @return list<string>
     *
     * @throws EnumBindingException on misconfiguration (empty list,
     *                              no Composer ClassLoader, unresolvable
     *                              namespace prefix)
     */
    public static function scan(array $namespacePrefixes): array
    {
        if ($namespacePrefixes === []) {
            throw EnumBindingException::forScan(
                EnumBindingReason::NoNamespacesConfigured,
                'EnumScanner::scan() requires at least one namespace prefix.',
            );
        }

        $normalized = [];
        foreach ($namespacePrefixes as $prefix) {
            $normalized[] = self::normalizePrefix($prefix);
        }
        $normalized = array_values(array_unique($normalized));
        sort($normalized);

        $cacheKey = implode("\0", $normalized);
        if (isset(self::$cache[$cacheKey])) {
            return self::$cache[$cacheKey];
        }

        $loader = self::resolveLoader();

        $candidates = [];
        foreach ($normalized as $prefix) {
            $candidates = [...$candidates, ...self::discoverCandidates($loader, $prefix)];
        }

        $matches = [];
        foreach (array_values(array_unique($candidates)) as $fqcn) {
            if (self::isBoundEnum($fqcn)) {
                $matches[] = $fqcn;
            }
        }

        $matches = array_values(array_unique($matches));
        sort($matches);

        return self::$cache[$cacheKey] = $matches;
    }

    /**
     * Inject a synthetic ClassLoader so unit tests don't have to mutate the
     * global autoloader stack.
     *
     * @internal Test seam — never call from production code.
     */
    public static function overrideClassLoaderForTesting(?ClassLoader $loader): void
    {
        self::$loaderOverride = $loader;
        self::$forceUnavailable = false;
        self::$cache = [];
    }

    /**
     * Simulate environments where no Composer ClassLoader is registered
     * (e.g., custom autoloader). The next `scan()` call will raise
     * {@see EnumBindingReason::ScanComposerLoaderUnavailable}.
     *
     * @internal Test seam — never call from production code.
     */
    public static function forceLoaderUnavailableForTesting(): void
    {
        self::$loaderOverride = null;
        self::$forceUnavailable = true;
        self::$cache = [];
    }

    /**
     * @internal Lifecycle hook for test isolation; mirrors `OpenApiSpecLoader::reset()`.
     */
    public static function reset(): void
    {
        self::$loaderOverride = null;
        self::$forceUnavailable = false;
        self::$cache = [];
    }

    private static function normalizePrefix(string $prefix): string
    {
        $stripped = ltrim($prefix, '\\');

        return rtrim($stripped, '\\') . '\\';
    }

    private static function resolveLoader(): ClassLoader
    {
        if (self::$forceUnavailable) {
            throw EnumBindingException::forScan(
                EnumBindingReason::ScanComposerLoaderUnavailable,
                'Composer ClassLoader unavailable; cannot auto-discover enums.',
            );
        }

        if (self::$loaderOverride !== null) {
            return self::$loaderOverride;
        }

        $autoloadFns = spl_autoload_functions();
        if ($autoloadFns !== false) {
            foreach ($autoloadFns as $fn) {
                if (is_array($fn) && $fn[0] instanceof ClassLoader) {
                    return $fn[0];
                }
            }
        }

        throw EnumBindingException::forScan(
            EnumBindingReason::ScanComposerLoaderUnavailable,
            'Composer ClassLoader unavailable; cannot auto-discover enums.',
        );
    }

    /**
     * @return list<string>
     */
    private static function discoverCandidates(ClassLoader $loader, string $prefix): array
    {
        $candidates = self::collectFromClassmap($loader, $prefix);
        $candidates = [...$candidates, ...self::collectFromPsr4($loader, $prefix)];

        if ($candidates === [] && !self::prefixHasResolvableRoot($loader, $prefix)) {
            throw EnumBindingException::forScan(
                EnumBindingReason::ScanNamespaceUnresolvable,
                sprintf(
                    'Namespace prefix "%s" is not registered as a Composer PSR-4 root '
                    . 'and matches no entries in the classmap. Check that the prefix '
                    . 'matches an autoload.psr-4 entry in composer.json.',
                    $prefix,
                ),
            );
        }

        return $candidates;
    }

    /**
     * @return list<string>
     */
    private static function collectFromClassmap(ClassLoader $loader, string $prefix): array
    {
        $matches = [];
        foreach (array_keys($loader->getClassMap()) as $fqcn) {
            if (str_starts_with($fqcn, $prefix)) {
                $matches[] = $fqcn;
            }
        }

        return $matches;
    }

    /**
     * @return list<string>
     */
    private static function collectFromPsr4(ClassLoader $loader, string $prefix): array
    {
        $directories = self::resolvePsr4Directories($loader, $prefix);
        if ($directories === []) {
            return [];
        }

        $candidates = [];
        foreach ($directories as $entry) {
            [$registeredPrefix, $directory] = $entry;
            if (!is_dir($directory)) {
                continue;
            }

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator(
                    $directory,
                    FilesystemIterator::SKIP_DOTS | FilesystemIterator::FOLLOW_SYMLINKS,
                ),
            );

            /** @var SplFileInfo $file */
            foreach ($iterator as $file) {
                if (!$file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }

                $fqcn = self::deriveFqcnFromFile(
                    $registeredPrefix,
                    $directory,
                    $file->getPathname(),
                );
                if ($fqcn === null) {
                    continue;
                }

                if (!str_starts_with($fqcn, $prefix)) {
                    continue;
                }

                $candidates[] = $fqcn;
            }
        }

        return $candidates;
    }

    /**
     * Resolve `$prefix` to one or more `[registeredPrefix, directory]`
     * tuples. If `$prefix` is registered directly in PSR-4, return its
     * directories as-is; otherwise look for the longest registered prefix
     * that is an ancestor of `$prefix` and append the remainder as a
     * sub-directory.
     *
     * @return list<array{0: string, 1: string}>
     */
    private static function resolvePsr4Directories(ClassLoader $loader, string $prefix): array
    {
        $psr4 = $loader->getPrefixesPsr4();

        if (isset($psr4[$prefix])) {
            return self::expandDirectories($prefix, $psr4[$prefix], '');
        }

        $bestMatch = null;
        $bestMatchLength = 0;
        foreach ($psr4 as $registeredPrefix => $directories) {
            if (!str_starts_with($prefix, $registeredPrefix)) {
                continue;
            }
            if (strlen($registeredPrefix) > $bestMatchLength) {
                $bestMatch = [$registeredPrefix, $directories];
                $bestMatchLength = strlen($registeredPrefix);
            }
        }

        if ($bestMatch === null) {
            return [];
        }

        [, $directories] = $bestMatch;
        $remainder = substr($prefix, $bestMatchLength);
        $subPath = str_replace('\\', '/', rtrim($remainder, '\\'));

        // The walker reconstructs FQCNs by stripping the directory root and
        // prepending a namespace prefix. When we descend into a sub-directory
        // that corresponds to a sub-namespace, the requested prefix — not the
        // registered ancestor — is the right namespace root for that walk.
        return self::expandDirectories($prefix, $directories, $subPath);
    }

    /**
     * @param list<string> $directories
     *
     * @return list<array{0: string, 1: string}>
     */
    private static function expandDirectories(string $registeredPrefix, array $directories, string $subPath): array
    {
        $expanded = [];
        foreach ($directories as $directory) {
            $base = rtrim($directory, '/');
            $resolved = $subPath === '' ? $base : $base . '/' . $subPath;
            $expanded[] = [$registeredPrefix, $resolved];
        }

        return $expanded;
    }

    private static function prefixHasResolvableRoot(ClassLoader $loader, string $prefix): bool
    {
        $psr4 = $loader->getPrefixesPsr4();
        if (isset($psr4[$prefix])) {
            return true;
        }

        foreach (array_keys($psr4) as $registeredPrefix) {
            if (str_starts_with($prefix, $registeredPrefix)) {
                return true;
            }
        }

        foreach (array_keys($loader->getClassMap()) as $fqcn) {
            if (str_starts_with($fqcn, $prefix)) {
                return true;
            }
        }

        return false;
    }

    private static function deriveFqcnFromFile(
        string $registeredPrefix,
        string $rootDirectory,
        string $filePath,
    ): ?string {
        $rootDirectory = rtrim($rootDirectory, '/');
        $rootWithSlash = $rootDirectory . '/';

        if (!str_starts_with($filePath, $rootWithSlash)) {
            return null;
        }

        $relative = substr($filePath, strlen($rootWithSlash));
        if (!str_ends_with($relative, '.php')) {
            return null;
        }

        $relativeWithoutExt = substr($relative, 0, -4);
        $classPart = str_replace('/', '\\', $relativeWithoutExt);

        return $registeredPrefix . $classPart;
    }

    private static function isBoundEnum(string $fqcn): bool
    {
        // `enum_exists()` and `ReflectionEnum` are intentionally NOT wrapped
        // in a `catch (Throwable)`: a `ParseError` from a broken enum source
        // file or an `Error` from a missing parent class is exactly the
        // bootstrap-time misconfiguration this library exists to surface
        // (issue #134). Swallowing them would convert a real bug into a
        // silent "no bound enums found" pass. Only the narrow case where
        // `enum_exists()` returned true but ReflectionEnum then disagrees
        // (autoloader race / stub mismatch) is caught.
        if (!enum_exists($fqcn)) {
            return false;
        }

        try {
            $reflection = new ReflectionEnum($fqcn);
        } catch (ReflectionException) {
            return false;
        }
        if (!$reflection->isBacked()) {
            return false;
        }

        return $reflection->getAttributes(BoundToOpenApiEnum::class) !== [];
    }
}
