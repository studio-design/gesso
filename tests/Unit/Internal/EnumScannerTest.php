<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit\Internal;

use Composer\Autoload\ClassLoader;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\Gesso\Exception\EnumBindingException;
use Studio\Gesso\Exception\EnumBindingReason;
use Studio\Gesso\Internal\EnumScanner;
use Studio\Gesso\Tests\Unit\Internal\Fixture\EnumScanner\AnotherBoundEnum;
use Studio\Gesso\Tests\Unit\Internal\Fixture\EnumScanner\CleanBoundEnum;
use Studio\Gesso\Tests\Unit\Internal\Fixture\EnumScanner\PlainClass;
use Studio\Gesso\Tests\Unit\Internal\Fixture\EnumScanner\PureUnattributedEnum;
use Studio\Gesso\Tests\Unit\Internal\Fixture\EnumScanner\UnattributedEnum;

final class EnumScannerTest extends TestCase
{
    private const FIXTURE_NS = 'Studio\\Gesso\\Tests\\Unit\\Internal\\Fixture\\EnumScanner\\';
    private const FIXTURE_DIR = __DIR__ . '/Fixture/EnumScanner';

    protected function setUp(): void
    {
        parent::setUp();
        EnumScanner::reset();
    }

    protected function tearDown(): void
    {
        EnumScanner::reset();
        parent::tearDown();
    }

    #[Test]
    public function scan_returns_every_backed_enum_in_namespace(): void
    {
        $loader = new ClassLoader();
        $loader->addClassMap([
            CleanBoundEnum::class => self::FIXTURE_DIR . '/CleanBoundEnum.php',
            AnotherBoundEnum::class => self::FIXTURE_DIR . '/AnotherBoundEnum.php',
            UnattributedEnum::class => self::FIXTURE_DIR . '/UnattributedEnum.php',
            PureUnattributedEnum::class => self::FIXTURE_DIR . '/PureUnattributedEnum.php',
            PlainClass::class => self::FIXTURE_DIR . '/PlainClass.php',
        ]);
        EnumScanner::overrideClassLoaderForTesting($loader);

        $found = EnumScanner::scan([self::FIXTURE_NS]);

        $this->assertSame(
            [AnotherBoundEnum::class, CleanBoundEnum::class, UnattributedEnum::class],
            $found,
        );
    }

    #[Test]
    public function scan_returns_empty_list_when_only_pure_enums_and_classes_are_found(): void
    {
        $loader = new ClassLoader();
        $loader->addClassMap([
            PureUnattributedEnum::class => self::FIXTURE_DIR . '/PureUnattributedEnum.php',
            PlainClass::class => self::FIXTURE_DIR . '/PlainClass.php',
        ]);
        EnumScanner::overrideClassLoaderForTesting($loader);

        $found = EnumScanner::scan([self::FIXTURE_NS]);

        $this->assertSame([], $found);
    }

    #[Test]
    public function scan_throws_for_unresolvable_namespace_prefix(): void
    {
        $loader = new ClassLoader();
        EnumScanner::overrideClassLoaderForTesting($loader);

        try {
            EnumScanner::scan(['Some\\Unknown\\Namespace\\']);
            $this->fail('Expected EnumBindingException');
        } catch (EnumBindingException $e) {
            $this->assertSame(EnumBindingReason::ScanNamespaceUnresolvable, $e->reason);
            $this->assertStringContainsString('Some\\Unknown\\Namespace\\', $e->getMessage());
        }
    }

    #[Test]
    public function scan_throws_when_namespaces_list_is_empty(): void
    {
        try {
            EnumScanner::scan([]);
            $this->fail('Expected EnumBindingException');
        } catch (EnumBindingException $e) {
            $this->assertSame(EnumBindingReason::NoNamespacesConfigured, $e->reason);
        }
    }

    #[Test]
    public function scan_throws_when_classloader_unavailable(): void
    {
        EnumScanner::forceLoaderUnavailableForTesting();

        try {
            EnumScanner::scan([self::FIXTURE_NS]);
            $this->fail('Expected EnumBindingException');
        } catch (EnumBindingException $e) {
            $this->assertSame(EnumBindingReason::ScanComposerLoaderUnavailable, $e->reason);
        }
    }

    #[Test]
    public function scan_handles_multiple_namespaces_with_dedup(): void
    {
        $loader = new ClassLoader();
        $loader->addClassMap([
            CleanBoundEnum::class => self::FIXTURE_DIR . '/CleanBoundEnum.php',
        ]);
        EnumScanner::overrideClassLoaderForTesting($loader);

        $found = EnumScanner::scan([
            self::FIXTURE_NS,
            self::FIXTURE_NS,
        ]);

        $this->assertSame([CleanBoundEnum::class], $found);
    }

    #[Test]
    public function scan_uses_classmap_when_authoritative(): void
    {
        $loader = new ClassLoader();
        $loader->addClassMap([
            CleanBoundEnum::class => self::FIXTURE_DIR . '/CleanBoundEnum.php',
        ]);
        EnumScanner::overrideClassLoaderForTesting($loader);

        $found = EnumScanner::scan([self::FIXTURE_NS]);

        $this->assertSame([CleanBoundEnum::class], $found);
    }

    #[Test]
    public function scan_falls_back_to_psr4_walk_when_classmap_empty(): void
    {
        $loader = new ClassLoader();
        $loader->setPsr4(self::FIXTURE_NS, [self::FIXTURE_DIR]);
        EnumScanner::overrideClassLoaderForTesting($loader);

        $found = EnumScanner::scan([self::FIXTURE_NS]);

        $this->assertSame(
            [AnotherBoundEnum::class, CleanBoundEnum::class, UnattributedEnum::class],
            $found,
        );
    }

    #[Test]
    public function scan_resolves_subnamespace_via_longest_matching_psr4_root(): void
    {
        $loader = new ClassLoader();
        $loader->setPsr4(
            'Studio\\Gesso\\Tests\\Unit\\Internal\\Fixture\\',
            [__DIR__ . '/Fixture'],
        );
        EnumScanner::overrideClassLoaderForTesting($loader);

        $found = EnumScanner::scan([self::FIXTURE_NS]);

        $this->assertSame(
            [AnotherBoundEnum::class, CleanBoundEnum::class, UnattributedEnum::class],
            $found,
        );
    }

    #[Test]
    public function scan_skips_pure_enums_silently(): void
    {
        $loader = new ClassLoader();
        $loader->addClassMap([
            PureUnattributedEnum::class => self::FIXTURE_DIR . '/PureUnattributedEnum.php',
            CleanBoundEnum::class => self::FIXTURE_DIR . '/CleanBoundEnum.php',
        ]);
        EnumScanner::overrideClassLoaderForTesting($loader);

        $found = EnumScanner::scan([self::FIXTURE_NS]);

        $this->assertSame([CleanBoundEnum::class], $found);
    }

    #[Test]
    public function scan_skips_traits_and_abstract_classes_without_failure(): void
    {
        $loader = new ClassLoader();
        $loader->setPsr4(self::FIXTURE_NS, [self::FIXTURE_DIR]);
        EnumScanner::overrideClassLoaderForTesting($loader);

        $found = EnumScanner::scan([self::FIXTURE_NS]);

        // SomeTrait.php and AbstractFixture.php live alongside the enum
        // fixtures; the walk must encounter and skip them without raising.
        $this->assertSame(
            [AnotherBoundEnum::class, CleanBoundEnum::class, UnattributedEnum::class],
            $found,
        );
    }

    #[Test]
    public function scan_caches_results_within_run(): void
    {
        $loader = new ClassLoader();
        $loader->addClassMap([
            CleanBoundEnum::class => self::FIXTURE_DIR . '/CleanBoundEnum.php',
        ]);
        EnumScanner::overrideClassLoaderForTesting($loader);

        $firstCall = EnumScanner::scan([self::FIXTURE_NS]);

        // Mutate the loader's classmap silently — a fresh call would normally
        // pick up the new entry. The cache must shield us from that.
        $loader->addClassMap([
            AnotherBoundEnum::class => self::FIXTURE_DIR . '/AnotherBoundEnum.php',
        ]);
        $secondCall = EnumScanner::scan([self::FIXTURE_NS]);

        $this->assertSame($firstCall, $secondCall);
        $this->assertSame([CleanBoundEnum::class], $secondCall);
    }

    #[Test]
    public function reset_clears_cache(): void
    {
        $loader = new ClassLoader();
        $loader->addClassMap([
            CleanBoundEnum::class => self::FIXTURE_DIR . '/CleanBoundEnum.php',
        ]);
        EnumScanner::overrideClassLoaderForTesting($loader);
        EnumScanner::scan([self::FIXTURE_NS]);

        EnumScanner::reset();

        $emptyLoader = new ClassLoader();
        $emptyLoader->setPsr4(self::FIXTURE_NS, [__DIR__ . '/NonExistentDir']);
        EnumScanner::overrideClassLoaderForTesting($emptyLoader);
        $afterReset = EnumScanner::scan([self::FIXTURE_NS]);

        $this->assertSame([], $afterReset);
    }
}
