<?php

declare(strict_types=1);

namespace Studio\Gesso\Internal;

use Composer\InstalledVersions;
use Throwable;

use function preg_replace;

/**
 * Single source for the identity Gesso reports about itself: the Composer
 * package name and the installed version.
 *
 * Every machine-readable document that carries a `tool` block, and the
 * `gesso --version` branch in `bin/gesso`, read the version from here so the
 * three cannot drift apart (issue #509).
 *
 * @internal Not part of the package's public API. Do not use from user code.
 *           `bin/gesso` reads it by design; `docs/versioning.md` names the
 *           binary as the one place in the repository that crosses the
 *           `@internal` boundary deliberately.
 */
final class ToolVersion
{
    public const PACKAGE = 'studio-design/gesso';

    private function __construct() {}

    /**
     * Resolve the running tool version, or the string `'unknown'`.
     *
     * Composer's pretty version is the tag name (`v3.0.0-beta.1`) or the branch
     * name (`dev-main`); the normalized `getVersion()` form (`3.0.0.0-beta1`)
     * matches neither the tag nor Packagist (issue #765).
     *
     * The value is cosmetic — any failure here must never abort the report, so
     * the catch is intentionally broad: `OutOfBoundsException` is the documented
     * Composer 2.x "package not installed" path
     * (`vendor/composer/InstalledVersions.php`), but corrupted `installed.php`,
     * Composer 1.x/2.x metadata mismatches, or stripped vendor directories can
     * surface as other throwables. Silent by design — `'unknown'` is the
     * documented sentinel and every schema that carries the value forbids null,
     * so returning a string is enough.
     */
    public static function resolve(): string
    {
        try {
            $prettyVersion = InstalledVersions::getPrettyVersion(self::PACKAGE);
        } catch (Throwable) {
            return 'unknown';
        }

        return self::fromPrettyVersion($prettyVersion);
    }

    /**
     * Turn a Composer pretty version into the reported form: a tag loses its
     * leading `v`, and a branch install keeps its name (`dev-main`).
     */
    public static function fromPrettyVersion(?string $prettyVersion): string
    {
        if ($prettyVersion === null || $prettyVersion === '') {
            return 'unknown';
        }

        return preg_replace('/^v(?=\d)/', '', $prettyVersion) ?? $prettyVersion;
    }
}
