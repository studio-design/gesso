<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit\Internal;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\Gesso\Internal\ToolVersion;

final class ToolVersionTest extends TestCase
{
    #[Test]
    public function resolves_the_installed_version_of_this_package(): void
    {
        $version = ToolVersion::resolve();

        $this->assertNotSame('', $version);
        // The suite runs from the repository, so Composer metadata is readable
        // and the sentinel would mean the resolver stopped working.
        $this->assertNotSame('unknown', $version);
    }

    /**
     * `InstalledVersions::getVersion()` throws `OutOfBoundsException` for a
     * package it does not know (`vendor/composer/InstalledVersions.php`). The
     * documents that carry the value forbid null, so the sentinel has to be a
     * string.
     */
    #[Test]
    public function returns_the_unknown_sentinel_when_composer_metadata_is_unreadable(): void
    {
        $this->assertSame('unknown', ToolVersion::resolve('studio-design/not-installed'));
    }

    #[Test]
    public function package_name_matches_the_composer_manifest(): void
    {
        $this->assertSame('studio-design/gesso', ToolVersion::PACKAGE);
    }
}
