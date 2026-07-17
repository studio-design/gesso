<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit\Build;

use const JSON_THROW_ON_ERROR;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function dirname;
use function file_get_contents;
use function is_string;
use function json_decode;
use function preg_match;
use function sprintf;

/**
 * Repo-level invariant tests for `release-please-config.json`.
 *
 * release-please's pre-major bump flags (`bump-minor-pre-major` /
 * `bump-patch-for-minor-pre-major`) MUST stay false post-v1.0.0; flipping
 * either to true would silently undershoot a major bump (a `feat!:` after
 * v1.0.0 would propose v1.X.0 instead of v2.0.0). The most realistic way
 * this regresses is a copy-paste from a 0.x project's config. These
 * assertions catch that copy-paste in CI before any wrong release ships.
 */
class ReleasePleaseConfigTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $config;

    protected function setUp(): void
    {
        parent::setUp();

        $path = dirname(__DIR__, 3) . '/release-please-config.json';
        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new RuntimeException(sprintf('Could not read %s', $path));
        }

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        $this->config = $decoded;
    }

    #[Test]
    public function bump_minor_pre_major_is_explicitly_false(): void
    {
        $this->assertSame(
            false,
            $this->config['bump-minor-pre-major'] ?? null,
            'bump-minor-pre-major MUST be explicitly false post-v1.0.0. Removing or flipping '
                . 'this flag would silently undershoot a major bump (feat! → 1.X.0 instead of '
                . '2.0.0). See CONTRIBUTING.md > Releases.',
        );
    }

    #[Test]
    public function bump_patch_for_minor_pre_major_is_explicitly_false(): void
    {
        $this->assertSame(
            false,
            $this->config['bump-patch-for-minor-pre-major'] ?? null,
            'bump-patch-for-minor-pre-major MUST be explicitly false post-v1.0.0. Same '
                . 'undershoot risk as bump-minor-pre-major.',
        );
    }

    #[Test]
    public function release_type_is_simple(): void
    {
        // `simple` only updates CHANGELOG.md + the manifest, which matches
        // this repo (composer.json carries no `version` field — Packagist
        // reads versions from git tags). Switching to `php` would inject a
        // `version` field into composer.json on every release, fighting
        // the Packagist convention.
        $this->assertSame(
            'simple',
            $this->config['release-type'] ?? null,
            'release-type MUST be "simple" — composer.json has no `version` field, '
                . 'Packagist reads versions from git tags.',
        );
    }

    #[Test]
    public function include_v_in_tag_is_true(): void
    {
        // Existing tags (v0.3.0..v1.0.0) all carry the `v` prefix; switching
        // mid-stream would create an `1.0.1` vs `v1.0.1` schism on Packagist.
        $this->assertSame(
            true,
            $this->config['include-v-in-tag'] ?? null,
            'include-v-in-tag MUST be true to match the existing tag history.',
        );
    }

    #[Test]
    public function package_root_is_configured(): void
    {
        $packages = $this->config['packages'] ?? null;
        $this->assertIsArray($packages);
        $this->assertArrayHasKey('.', $packages, 'single-package layout MUST configure root path "."');

        $rootPackage = $packages['.'] ?? null;
        $this->assertIsArray($rootPackage);
        $this->assertSame(
            'gesso',
            $rootPackage['package-name'] ?? null,
        );
    }

    #[Test]
    public function main_is_configured_for_the_v2_beta_line(): void
    {
        $this->assertSame(
            'prerelease',
            $this->config['versioning'] ?? null,
            'The prerelease versioning strategy is required to produce and later promote v2 beta versions.',
        );
        $this->assertSame(
            'beta',
            $this->config['prerelease-type'] ?? null,
            'The v2 prerelease line MUST use the documented beta identifier.',
        );
        $this->assertSame(
            true,
            $this->config['prerelease'] ?? null,
            'The v2 beta must be tagged as a GitHub prerelease. Set this to false only in the stable-promotion PR.',
        );
        $this->assertArrayNotHasKey(
            'release-as',
            $this->config,
            'release-as MUST be absent after v2.0.0-beta.1 so later beta releases can increment normally.',
        );
        $this->assertArrayNotHasKey(
            'changelog-sections',
            $this->config,
            'Stable-promotion changelog overrides MUST be absent while publishing beta releases.',
        );

        $rootPackage = $this->config['packages']['.'] ?? null;
        $this->assertIsArray($rootPackage);
        $this->assertArrayNotHasKey(
            'release-as',
            $rootPackage,
            'Package-level release-as MUST be absent after v2.0.0-beta.1 so it cannot override the root configuration.',
        );
    }

    #[Test]
    public function manifest_is_a_single_root_package_at_a_semver_version(): void
    {
        $manifestPath = dirname(__DIR__, 3) . '/.release-please-manifest.json';
        $raw = file_get_contents($manifestPath);
        $this->assertNotFalse($raw, sprintf('Could not read %s', $manifestPath));

        /** @var array<string, mixed> $manifest */
        $manifest = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);

        $this->assertCount(1, $manifest, 'manifest MUST configure a single package at root path');
        $this->assertArrayHasKey('.', $manifest);

        $version = $manifest['.'];
        $this->assertTrue(
            is_string($version) && preg_match('/^\d+\.\d+\.\d+(?:-[\w.]+)?$/', $version) === 1,
            sprintf('manifest version "%s" MUST be a SemVer string without leading "v"', is_string($version) ? $version : '<non-string>'),
        );
    }
}
