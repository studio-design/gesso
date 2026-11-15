<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit\Internal;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\Gesso\Internal\ToolVersion;

final class ToolVersionTest extends TestCase
{
    /**
     * @return iterable<string, array{?string, string}>
     */
    public static function provideReports_the_tag_form_of_the_pretty_versionCases(): iterable
    {
        yield 'stable tag' => ['v3.0.0', '3.0.0'];
        yield 'prerelease tag' => ['v3.0.0-beta.1', '3.0.0-beta.1'];
        yield 'tag without prefix' => ['3.0.0', '3.0.0'];
        yield 'branch install' => ['dev-main', 'dev-main'];
        yield 'aliased branch name' => ['3.x-dev', '3.x-dev'];
        yield 'no record' => [null, 'unknown'];
        yield 'empty record' => ['', 'unknown'];
    }

    #[Test]
    public function resolves_the_installed_version_of_this_package(): void
    {
        $version = ToolVersion::resolve();

        $this->assertNotSame('', $version);
        // The suite runs from the repository, so Composer metadata is readable
        // and the sentinel would mean the resolver stopped working.
        $this->assertNotSame('unknown', $version);
    }

    #[Test]
    #[DataProvider('provideReports_the_tag_form_of_the_pretty_versionCases')]
    public function reports_the_tag_form_of_the_pretty_version(?string $prettyVersion, string $expected): void
    {
        $this->assertSame($expected, ToolVersion::fromPrettyVersion($prettyVersion));
    }

    #[Test]
    public function package_name_matches_the_composer_manifest(): void
    {
        $this->assertSame('studio-design/gesso', ToolVersion::PACKAGE);
    }
}
