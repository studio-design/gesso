<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit\Internal;

use const E_USER_DEPRECATED;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\Gesso\Internal\Deprecations;

use function restore_error_handler;
use function set_error_handler;

final class DeprecationsTest extends TestCase
{
    /** @var list<string> */
    private array $captured = [];

    protected function setUp(): void
    {
        parent::setUp();
        Deprecations::resetForTesting();
        $this->captured = [];
        set_error_handler(function (int $errno, string $errstr): bool {
            if ($errno !== E_USER_DEPRECATED) {
                return false;
            }

            $this->captured[] = $errstr;

            return true;
        });
    }

    protected function tearDown(): void
    {
        restore_error_handler();
        Deprecations::resetForTesting();
        parent::tearDown();
    }

    /** @return array<string, array{string, string, string, string, string}> */
    public static function provideAn_empty_required_parameter_is_rejectedCases(): iterable
    {
        return [
            'empty id' => ['', 'A key', 'Another key', '3.0', '$id'],
            'empty subject' => ['x', '', 'Another key', '3.0', '$subject'],
            'empty replacement' => ['x', 'A key', '', '3.0', '$replacement'],
            'blank replacement' => ['x', 'A key', '   ', '3.0', '$replacement'],
            'empty removedIn' => ['x', 'A key', 'Another key', '', '$removedIn'],
            'blank removedIn' => ['x', 'A key', 'Another key', "\t", '$removedIn'],
        ];
    }

    #[Test]
    public function a_notice_names_its_replacement_and_removal_version(): void
    {
        Deprecations::notice(
            id: 'laravel.config.auto_inject_dummy_bearer',
            subject: "The Laravel config key 'auto_inject_dummy_bearer'",
            replacement: "'auto_inject_dummy_credentials'",
            removedIn: '3.0',
        );

        $this->assertCount(1, $this->captured);
        $this->assertStringStartsWith('[Gesso deprecation] ', $this->captured[0]);
        $this->assertStringContainsString("'auto_inject_dummy_credentials'", $this->captured[0]);
        $this->assertStringContainsString('removed in Gesso 3.0', $this->captured[0]);
    }

    #[Test]
    public function an_id_emits_once_per_process_but_keeps_counting(): void
    {
        for ($i = 0; $i < 3; $i++) {
            Deprecations::notice(
                id: 'phpunit.enum_spec_base_path',
                subject: "The 'enum_spec_base_path' parameter",
                replacement: "'spec_base_path'",
                removedIn: '3.0',
            );
        }

        $this->assertCount(1, $this->captured);
        $this->assertSame(['phpunit.enum_spec_base_path' => 3], Deprecations::counts());
    }

    #[Test]
    public function reset_clears_counts_and_re_arms_the_notice(): void
    {
        $this->notice('a');
        Deprecations::resetForTesting();
        $this->assertSame([], Deprecations::counts());

        $this->notice('a');
        $this->assertCount(2, $this->captured);
    }

    #[Test]
    #[DataProvider('provideAn_empty_required_parameter_is_rejectedCases')]
    public function an_empty_required_parameter_is_rejected(
        string $id,
        string $subject,
        string $replacement,
        string $removedIn,
        string $expected,
    ): void {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($expected);

        Deprecations::notice($id, $subject, $replacement, $removedIn);
    }

    #[Test]
    public function no_deprecation_produces_no_summary_line(): void
    {
        $this->assertNull(Deprecations::summaryLine());
    }

    #[Test]
    public function the_summary_line_lists_every_id_with_its_count(): void
    {
        $this->notice('b', times: 2);
        $this->notice('a', times: 31);

        $this->assertSame(
            '[Gesso deprecation] 2 deprecated surface(s) still in use, 33 call(s): a (31), b (2).'
            . " All are removed in Gesso 3.0.\n",
            Deprecations::summaryLine(),
        );
    }

    #[Test]
    public function mixed_removal_versions_are_reported_per_id(): void
    {
        // The trailing "all are removed in" sentence would be false here, so
        // each id has to carry its own version instead.
        $this->notice('a');
        $this->notice('b', removedIn: '4.0');

        $this->assertSame(
            '[Gesso deprecation] 2 deprecated surface(s) still in use, 2 call(s):'
            . " a (1, removed in 3.0), b (1, removed in 4.0).\n",
            Deprecations::summaryLine(),
        );
    }

    private function notice(string $id, int $times = 1, string $removedIn = '3.0'): void
    {
        for ($i = 0; $i < $times; $i++) {
            Deprecations::notice(
                id: $id,
                subject: 'The ' . $id . ' surface',
                replacement: 'its successor',
                removedIn: $removedIn,
            );
        }
    }
}
