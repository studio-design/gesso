<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit\Build;

use const JSON_THROW_ON_ERROR;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function array_diff;
use function array_filter;
use function array_map;
use function array_pop;
use function array_unique;
use function array_values;
use function dirname;
use function escapeshellarg;
use function exec;
use function explode;
use function file_exists;
use function file_get_contents;
use function implode;
use function in_array;
use function json_decode;
use function preg_match;
use function preg_match_all;
use function sort;
use function str_ends_with;
use function str_starts_with;
use function trim;

/**
 * Pins what `composer require studio-design/gesso` puts in a consumer's
 * `vendor/` (issue #513).
 *
 * The package is distributed as a GitHub-generated zipball, which honours
 * `.gitattributes` `export-ignore` and ignores `composer.json` entirely. The
 * `archive.exclude` list in `composer.json` only governs archives Composer
 * builds itself. Both declarations therefore have to exist, and they have to
 * agree — a path excluded in one and not the other means the package ships
 * differently depending on how it was fetched.
 */
final class PackageArchivePolicyTest extends TestCase
{
    /**
     * Tracked top-level paths that are shipped on purpose. A new tracked
     * top-level entry that is neither listed here nor `export-ignore`d fails
     * `every_tracked_top_level_path_is_classified()`, so the decision cannot
     * be skipped by accident.
     *
     * @var list<string>
     */
    private const SHIPPED_TOP_LEVEL = [
        'CHANGELOG.md',
        'LICENSE',
        'README.md',
        'SECURITY.md',
        'UPGRADING.md',
        'bin',
        'composer.json',
        'docs',
        'src',
        'stubs',
    ];

    /**
     * `archive.exclude` entries with no `.gitattributes` counterpart, because
     * they are build artifacts that are gitignored and therefore never in a
     * git archive to begin with. `composer archive` runs against a working
     * directory, so it still needs them.
     *
     * @var list<string>
     */
    private const BUILD_ARTIFACT_ONLY = [
        '/.php-cs-fixer.cache',
        '/.php-cs-fixer.pest.cache',
        '/.phpstan.cache',
        '/.phpunit.cache',
        '/composer.lock',
        '/node_modules',
        '/vendor',
    ];

    #[Test]
    public function gitattributes_declares_a_well_formed_export_ignore_set(): void
    {
        $entries = $this->exportIgnoreEntries();

        $this->assertNotEmpty($entries, '.gitattributes must declare export-ignore paths.');

        foreach ($entries as $entry) {
            $this->assertStringStartsWith(
                '/',
                $entry,
                'export-ignore paths must be anchored to the repository root.',
            );
            $this->assertFileExists(
                $this->root() . $entry,
                $entry . ' is export-ignored but does not exist; remove the dead entry.',
            );
        }
    }

    #[Test]
    public function composer_archive_exclude_agrees_with_gitattributes(): void
    {
        $exportIgnore = $this->exportIgnoreEntries();
        $archiveExclude = $this->archiveExcludeEntries();

        $missingFromComposer = array_values(array_diff($exportIgnore, $archiveExclude));
        $this->assertSame(
            [],
            $missingFromComposer,
            'export-ignore paths missing from composer.json archive.exclude: '
            . implode(', ', $missingFromComposer),
        );

        $missingFromGitattributes = array_values(
            array_diff($archiveExclude, $exportIgnore, self::BUILD_ARTIFACT_ONLY),
        );
        $this->assertSame(
            [],
            $missingFromGitattributes,
            'archive.exclude paths missing from .gitattributes (the GitHub zipball '
            . 'would still ship them): ' . implode(', ', $missingFromGitattributes),
        );
    }

    /**
     * Absorbed from the former `ComposerArchivePolicyTest`. These paths are
     * gitignored, so `export-ignore` never sees them — but `composer archive`
     * runs against a working directory where they exist.
     */
    #[Test]
    public function generated_paths_stay_excluded_from_composer_built_archives(): void
    {
        $excluded = $this->archiveExcludeEntries();

        foreach ([
            '/vendor',
            '/composer.lock',
            '/node_modules',
            '/docs/.vitepress/cache',
            '/docs/.vitepress/dist',
        ] as $generated) {
            $this->assertTrue(
                $this->isCoveredBy($generated, $excluded),
                $generated . ' must be excluded from composer-built archives, '
                . 'either directly or through an ancestor entry.',
            );
        }
    }

    #[Test]
    public function consumer_facing_paths_are_shipped(): void
    {
        foreach ([
            '/bin',
            '/composer.json',
            '/docs',
            '/src',
            '/stubs',
            '/CHANGELOG.md',
            '/LICENSE',
            '/README.md',
            '/SECURITY.md',
            // #499 specifies deprecation notices that point consumers at
            // UPGRADING.md; a package that excludes it is a dead link.
            '/UPGRADING.md',
        ] as $path) {
            $this->assertFalse(
                $this->isExcluded($path),
                $path . ' must ship to consumers.',
            );
        }
    }

    #[Test]
    public function development_and_internal_paths_are_not_shipped(): void
    {
        foreach ([
            '/.github',
            '/AGENTS.md',
            '/CLAUDE.md',
            '/benchmarks',
            // Internal design documents. A consumer's agent greps vendor/ and
            // reads whatever it finds as product documentation (issue #511).
            '/docs/superpowers',
            '/phpstan.neon.dist',
            '/phpunit.xml.dist',
            '/scripts',
            '/tests',
        ] as $path) {
            $this->assertTrue(
                $this->isExcluded($path),
                $path . ' must not ship to consumers.',
            );
        }
    }

    #[Test]
    public function every_tracked_top_level_path_is_classified(): void
    {
        $unclassified = [];

        foreach ($this->trackedTopLevelPaths() as $path) {
            if (in_array($path, self::SHIPPED_TOP_LEVEL, true)) {
                continue;
            }
            if ($this->isExcluded('/' . $path)) {
                continue;
            }

            $unclassified[] = $path;
        }

        $this->assertSame(
            [],
            $unclassified,
            'Tracked top-level paths are neither declared shipped nor export-ignored: '
            . implode(', ', $unclassified)
            . '. Add them to SHIPPED_TOP_LEVEL or to .gitattributes.',
        );
    }

    /**
     * A relative link that resolves inside the repository still dangles once
     * the target is `export-ignore`d, and `npm run docs:links` cannot see it
     * because it walks the repository rather than the archive.
     */
    #[Test]
    public function shipped_documentation_never_links_into_excluded_paths(): void
    {
        $dangling = [];

        foreach ($this->shippedMarkdownFiles() as $file) {
            foreach ($this->relativeLinksIn($file) as $link) {
                $target = $this->resolveAgainst($file, $link);

                if ($target === null) {
                    continue;
                }
                if (!$this->isExcluded('/' . $target) && file_exists($this->root() . '/' . $target)) {
                    continue;
                }

                $dangling[] = $file . ' -> ' . $link;
            }
        }

        $this->assertSame(
            [],
            $dangling,
            "Shipped documentation links to paths the package does not ship:\n  "
            . implode("\n  ", $dangling)
            . "\nUse an absolute https://github.com/studio-design/gesso/... URL, "
            . 'or ship the target.',
        );
    }

    private function root(): string
    {
        return dirname(__DIR__, 3);
    }

    /**
     * Repository-relative paths of every Markdown file that ends up in the
     * archive.
     *
     * @return list<string>
     */
    private function shippedMarkdownFiles(): array
    {
        $files = [];

        foreach ($this->trackedFiles() as $path) {
            if (!str_ends_with($path, '.md')) {
                continue;
            }
            if ($this->isExcluded('/' . $path)) {
                continue;
            }

            $files[] = $path;
        }

        sort($files);

        return $files;
    }

    /**
     * Markdown link targets plus `src` / `href` attributes from the inline
     * HTML the README uses for its logo, minus anything that is not a
     * repository-relative path.
     *
     * @return list<string>
     */
    private function relativeLinksIn(string $file): array
    {
        $contents = (string) file_get_contents($this->root() . '/' . $file);

        preg_match_all('#\[[^\]]*\]\(([^)\s]+)#', $contents, $markdown);
        preg_match_all('#(?:src|href)="([^"]+)"#', $contents, $html);

        $links = [];
        foreach ([...$markdown[1], ...$html[1]] as $link) {
            if (preg_match('#^(?:[a-z][a-z0-9+.-]*:|//|\#)#i', $link) === 1) {
                continue;
            }

            $links[] = $link;
        }

        return $links;
    }

    /**
     * Repository-relative target of `$link` as written in `$file`, or null
     * when the link is a bare fragment or escapes the repository.
     */
    private function resolveAgainst(string $file, string $link): ?string
    {
        $link = explode('#', explode('?', $link, 2)[0], 2)[0];
        if ($link === '') {
            return null;
        }

        $segments = explode('/', dirname($file));
        if ($segments === ['.']) {
            $segments = [];
        }

        foreach (explode('/', $link) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                if ($segments === []) {
                    return null;
                }
                array_pop($segments);

                continue;
            }

            $segments[] = $segment;
        }

        return $segments === [] ? null : implode('/', $segments);
    }

    /**
     * `docs/` ships but two of its subtrees do not, so exclusion is a prefix
     * test rather than a top-level lookup.
     */
    private function isExcluded(string $path): bool
    {
        return $this->isCoveredBy($path, $this->exportIgnoreEntries());
    }

    /** @param list<string> $entries */
    private function isCoveredBy(string $path, array $entries): bool
    {
        foreach ($entries as $entry) {
            if ($path === $entry || str_starts_with($path, $entry . '/')) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    private function exportIgnoreEntries(): array
    {
        $contents = file_get_contents($this->root() . '/.gitattributes');
        $this->assertIsString($contents, '.gitattributes must exist and be readable.');

        $entries = [];
        foreach (explode("\n", $contents) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $this->assertSame(
                1,
                preg_match('#^(\S+)\s+export-ignore$#', $line, $matches),
                'Unrecognised .gitattributes line: ' . $line,
            );

            $entries[] = $matches[1];
        }

        sort($entries);

        return $entries;
    }

    /** @return list<string> */
    private function archiveExcludeEntries(): array
    {
        $composer = json_decode(
            (string) file_get_contents($this->root() . '/composer.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        $this->assertIsArray($composer);
        $this->assertArrayHasKey('archive', $composer);
        $this->assertIsArray($composer['archive']);
        $this->assertArrayHasKey('exclude', $composer['archive']);
        $this->assertIsArray($composer['archive']['exclude']);

        /** @var list<string> $exclude */
        $exclude = array_values($composer['archive']['exclude']);
        sort($exclude);

        return $exclude;
    }

    /**
     * Tracked paths come from git rather than the filesystem so untracked
     * local state (`.claude/`, editor scratch, plugin caches) does not have to
     * be enumerated here.
     *
     * @return list<string>
     */
    private function trackedTopLevelPaths(): array
    {
        $top = array_map(
            static fn(string $path): string => explode('/', $path)[0],
            $this->trackedFiles(),
        );

        $top = array_values(array_unique(array_filter($top, static fn(string $p): bool => $p !== '')));
        sort($top);

        return $top;
    }

    /** @return list<string> */
    private function trackedFiles(): array
    {
        $output = [];
        $status = 0;
        exec('git -C ' . escapeshellarg($this->root()) . ' ls-files 2>/dev/null', $output, $status);

        if ($status !== 0 || $output === []) {
            $this->markTestSkipped('git ls-files is unavailable; cannot enumerate tracked paths.');
        }

        return $output;
    }
}
