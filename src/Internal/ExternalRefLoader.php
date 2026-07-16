<?php

declare(strict_types=1);

namespace Studio\Gesso\Internal;

use const DIRECTORY_SEPARATOR;
use const PATHINFO_EXTENSION;

use Studio\Gesso\Exception\InvalidOpenApiSpecException;
use Studio\Gesso\Exception\InvalidOpenApiSpecReason;

use function array_pop;
use function array_slice;
use function dirname;
use function error_clear_last;
use function error_get_last;
use function explode;
use function file_exists;
use function file_get_contents;
use function implode;
use function is_readable;
use function pathinfo;
use function realpath;
use function rtrim;
use function sprintf;
use function str_replace;
use function str_starts_with;
use function strtolower;
use function substr;

/**
 * Resolves and decodes external `$ref` target documents from the local
 * filesystem. Used by `OpenApiRefResolver` when it encounters a `$ref`
 * whose path part is not an in-document JSON Pointer.
 *
 * Each resolution call passes its own `$documentCache` so the same
 * external file is decoded once even if multiple sibling refs point at
 * different fragments of it. The cache lives only for the duration of
 * one `OpenApiRefResolver::resolve()` call.
 *
 * Canonical targets must remain inside an explicitly allowed root. When
 * no root is supplied, the source document's directory is the boundary.
 * Validation happens after realpath(), so both `../` traversal and symlinks
 * that escape the boundary are rejected. `file://` URLs are rejected
 * separately by the resolver.
 *
 * @internal Not part of the package's public API. Do not use from user code.
 */
final class ExternalRefLoader
{
    private function __construct() {}

    /**
     * Resolve `$refPath` relative to `$sourceFile`, decode the target file,
     * and return the canonical absolute path together with the decoded array.
     *
     * `$refPath` is interpreted as either an absolute filesystem path
     * (when it begins with `/`) or a path relative to the directory of
     * `$sourceFile`. Symlinks are followed via `realpath()`, so two refs
     * that resolve to the same canonical target share a cache entry.
     *
     * @param array<string, array<string, mixed>> $documentCache by-ref cache keyed by absolute path
     * @param list<string> $allowedLocalRefRoots canonical filesystem roots local refs may read from
     *
     * @throws InvalidOpenApiSpecException when the file cannot be located, decoded, or has an unsupported extension
     */
    public static function loadDocument(
        string $refPath,
        string $sourceFile,
        array &$documentCache,
        array $allowedLocalRefRoots = [],
    ): LoadedDocument {
        $candidate = str_starts_with($refPath, '/')
            ? $refPath
            : dirname($sourceFile) . '/' . $refPath;
        if ($allowedLocalRefRoots === []) {
            $allowedLocalRefRoots = [dirname($sourceFile)];
        }

        // realpath() returns false for several distinct conditions
        // (missing file, unreadable parent, broken symlink,
        // open_basedir restriction). Disambiguate via file_exists()
        // against the pre-canonicalized candidate so the user sees
        // the right reason category.
        $absolutePath = realpath($candidate);
        if ($absolutePath === false) {
            // Check both the lexical path (so missing components cannot hide a
            // `..` escape) and the filesystem path (so existing symlink
            // ancestors cannot hide an escape).
            $lexicalAncestor = self::deepestCanonicalAncestor(self::normalizePathLexically($candidate));
            $resolvedAncestor = self::deepestCanonicalAncestor($candidate);
            if ($lexicalAncestor === null ||
                $resolvedAncestor === null ||
                !self::isInsideAllowedRoot($lexicalAncestor, $allowedLocalRefRoots) ||
                !self::isInsideAllowedRoot($resolvedAncestor, $allowedLocalRefRoots)
            ) {
                throw self::outsideAllowedRoot($refPath);
            }
            if (!file_exists($candidate)) {
                throw new InvalidOpenApiSpecException(
                    InvalidOpenApiSpecReason::LocalRefNotFound,
                    sprintf(
                        'Local $ref target not found: %s (resolved from %s)',
                        $refPath,
                        $sourceFile,
                    ),
                    ref: $refPath,
                );
            }

            throw new InvalidOpenApiSpecException(
                InvalidOpenApiSpecReason::LocalRefUnreadable,
                sprintf(
                    'Local $ref target exists but cannot be canonicalized: %s '
                    . '(check permissions on the parent directory or symlink chain).',
                    $refPath,
                ),
                ref: $refPath,
            );
        }

        if (!self::isInsideAllowedRoot($absolutePath, $allowedLocalRefRoots)) {
            throw self::outsideAllowedRoot($refPath);
        }

        if (isset($documentCache[$absolutePath])) {
            return new LoadedDocument($absolutePath, $documentCache[$absolutePath]);
        }

        $extension = strtolower((string) pathinfo($absolutePath, PATHINFO_EXTENSION));
        if ($extension === '') {
            throw new InvalidOpenApiSpecException(
                InvalidOpenApiSpecReason::UnsupportedExtension,
                sprintf('$ref target has no file extension: %s (resolved to %s)', $refPath, $absolutePath),
                ref: $refPath,
            );
        }

        $decoded = match ($extension) {
            'json' => self::readAndDecodeJson($absolutePath, $refPath),
            'yaml', 'yml' => self::readAndDecodeYaml($absolutePath, $refPath),
            default => throw new InvalidOpenApiSpecException(
                InvalidOpenApiSpecReason::UnsupportedExtension,
                sprintf('Unsupported $ref target extension: .%s for %s', $extension, $refPath),
                ref: $refPath,
            ),
        };

        $documentCache[$absolutePath] = $decoded;

        return new LoadedDocument($absolutePath, $decoded);
    }

    /** @param list<string> $allowedLocalRefRoots */
    private static function isInsideAllowedRoot(string $absolutePath, array $allowedLocalRefRoots): bool
    {
        foreach ($allowedLocalRefRoots as $root) {
            $canonicalRoot = realpath($root);
            if ($canonicalRoot === false) {
                continue;
            }

            $canonicalRoot = rtrim($canonicalRoot, '/\\');
            if ($absolutePath === $canonicalRoot ||
                str_starts_with($absolutePath, $canonicalRoot . DIRECTORY_SEPARATOR)
            ) {
                return true;
            }
        }

        return false;
    }

    private static function deepestCanonicalAncestor(string $candidate): ?string
    {
        $ancestor = $candidate;

        while (true) {
            $canonicalAncestor = realpath($ancestor);
            if ($canonicalAncestor !== false) {
                return $canonicalAncestor;
            }

            $parent = dirname($ancestor);
            if ($parent === $ancestor) {
                return null;
            }

            $ancestor = $parent;
        }
    }

    private static function normalizePathLexically(string $path, string $separator = DIRECTORY_SEPARATOR): string
    {
        if ($separator === '\\') {
            $path = str_replace('/', '\\', $path);
        }

        $prefix = '';
        if ($separator === '\\' && str_starts_with($path, '\\\\')) {
            $prefix = '\\\\';
            $path = substr($path, 2);
            $uncSegments = explode($separator, $path);
            if ($uncSegments[0] !== '' && isset($uncSegments[1]) && $uncSegments[1] !== '') {
                $prefix .= $uncSegments[0] . $separator . $uncSegments[1] . $separator;
                $path = implode($separator, array_slice($uncSegments, 2));
            }
        } elseif ($separator === '\\' && isset($path[2]) && $path[1] === ':' && $path[2] === '\\') {
            $prefix = substr($path, 0, 3);
            $path = substr($path, 3);
        } elseif (str_starts_with($path, $separator)) {
            $prefix = $separator;
            $path = substr($path, 1);
        }

        $segments = [];
        foreach (explode($separator, $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                if ($segments !== []) {
                    array_pop($segments);
                } elseif ($prefix === '') {
                    $segments[] = '..';
                }

                continue;
            }

            $segments[] = $segment;
        }

        return $prefix . implode($separator, $segments);
    }

    private static function outsideAllowedRoot(string $refPath): InvalidOpenApiSpecException
    {
        return new InvalidOpenApiSpecException(
            InvalidOpenApiSpecReason::LocalRefOutsideAllowedRoot,
            sprintf('Local $ref target is outside the configured local-ref roots: %s.', $refPath),
            ref: $refPath,
        );
    }

    /** @return array<string, mixed> */
    private static function readAndDecodeJson(string $absolutePath, string $refPath): array
    {
        // realpath() proved the file exists and is reachable; a read
        // failure here is a runtime I/O issue (permissions revoked
        // mid-process, disk error, race with unlink). Surface as
        // LocalRefUnreadable rather than the not-found category so
        // operators can tell the difference. error_clear_last/get_last
        // gives us the underlying message without scraping the warning.
        error_clear_last();
        $content = @file_get_contents($absolutePath);
        if ($content === false) {
            $error = error_get_last();
            $detail = $error['message'] ?? 'unknown read error';

            throw new InvalidOpenApiSpecException(
                InvalidOpenApiSpecReason::LocalRefUnreadable,
                sprintf('Local $ref target unreadable: %s (path: %s, %s)', $refPath, $absolutePath, $detail),
                ref: $refPath,
            );
        }

        return SpecDocumentDecoder::decodeJson($content, $refPath);
    }

    /** @return array<string, mixed> */
    private static function readAndDecodeYaml(string $absolutePath, string $refPath): array
    {
        // is_readable() catches the most common pre-parse I/O failure
        // (permissions revoked between realpath() and the actual read).
        // Symfony's YAML parser would otherwise mask I/O errors as
        // ParseException, hiding the root cause.
        if (!is_readable($absolutePath)) {
            throw new InvalidOpenApiSpecException(
                InvalidOpenApiSpecReason::LocalRefUnreadable,
                sprintf('Local $ref target unreadable: %s (path: %s)', $refPath, $absolutePath),
                ref: $refPath,
            );
        }

        $content = (string) file_get_contents($absolutePath);

        return SpecDocumentDecoder::decodeYaml($content, $refPath);
    }
}
