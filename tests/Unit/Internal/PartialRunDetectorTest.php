<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Tests\Unit\Internal;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\OpenApiContractTesting\Internal\PartialRunDetector;

final class PartialRunDetectorTest extends TestCase
{
    /**
     * @return iterable<string, array{0: array<string, bool|list<non-empty-string>>, 1: string}>
     */
    public static function providePartial_when_any_single_signal_activeCases(): iterable
    {
        yield 'cli path args' => [['hasCliArguments' => true], 'test path'];
        yield '--filter' => [['hasFilter' => true], '--filter'];
        yield '--exclude-filter' => [['hasExcludeFilter' => true], '--exclude-filter'];
        yield '--group' => [['hasGroups' => true], '--group'];
        yield '--exclude-group' => [['hasExcludeGroups' => true], '--exclude-group'];
        yield '--testsuite include' => [['includeTestSuites' => ['Unit']], '--testsuite'];
        yield '--exclude-testsuite' => [['excludeTestSuites' => ['Integration']], '--exclude-testsuite'];
        yield '--covers' => [['hasTestsCovering' => true], '--covers'];
        yield '--uses' => [['hasTestsUsing' => true], '--uses'];
        yield '--requires-php-extension' => [['hasTestsRequiringPhpExtension' => true], '--requires-php-extension'];
    }

    #[Test]
    public function full_run_when_no_signals_set(): void
    {
        $detector = PartialRunDetector::fromSignals(
            hasCliArguments: false,
            hasFilter: false,
            hasExcludeFilter: false,
            hasGroups: false,
            hasExcludeGroups: false,
            includeTestSuites: [],
            excludeTestSuites: [],
            hasTestsCovering: false,
            hasTestsUsing: false,
            hasTestsRequiringPhpExtension: false,
        );

        $this->assertFalse($detector->isPartial);
        $this->assertNull($detector->reason);
    }

    /**
     * @param array{
     *     hasCliArguments?: bool,
     *     hasFilter?: bool,
     *     hasExcludeFilter?: bool,
     *     hasGroups?: bool,
     *     hasExcludeGroups?: bool,
     *     includeTestSuites?: list<non-empty-string>,
     *     excludeTestSuites?: list<non-empty-string>,
     *     hasTestsCovering?: bool,
     *     hasTestsUsing?: bool,
     *     hasTestsRequiringPhpExtension?: bool,
     * } $signal
     */
    #[Test]
    #[DataProvider('providePartial_when_any_single_signal_activeCases')]
    public function partial_when_any_single_signal_active(array $signal, string $expectedReasonFragment): void
    {
        $detector = PartialRunDetector::fromSignals(
            hasCliArguments: $signal['hasCliArguments'] ?? false,
            hasFilter: $signal['hasFilter'] ?? false,
            hasExcludeFilter: $signal['hasExcludeFilter'] ?? false,
            hasGroups: $signal['hasGroups'] ?? false,
            hasExcludeGroups: $signal['hasExcludeGroups'] ?? false,
            includeTestSuites: $signal['includeTestSuites'] ?? [],
            excludeTestSuites: $signal['excludeTestSuites'] ?? [],
            hasTestsCovering: $signal['hasTestsCovering'] ?? false,
            hasTestsUsing: $signal['hasTestsUsing'] ?? false,
            hasTestsRequiringPhpExtension: $signal['hasTestsRequiringPhpExtension'] ?? false,
        );

        $this->assertTrue($detector->isPartial);
        $this->assertNotNull($detector->reason);
        $this->assertStringContainsString($expectedReasonFragment, $detector->reason);
    }

    #[Test]
    public function reason_lists_all_active_signals_in_stable_order(): void
    {
        // 複数の signal を立てたとき, reason に全部含まれることを確認。
        // 順序は detector の出力が安定 (declaration order) であることをピンする。
        $detector = PartialRunDetector::fromSignals(
            hasCliArguments: true,
            hasFilter: true,
            hasExcludeFilter: false,
            hasGroups: true,
            hasExcludeGroups: false,
            includeTestSuites: ['Unit'],
            excludeTestSuites: [],
            hasTestsCovering: false,
            hasTestsUsing: false,
            hasTestsRequiringPhpExtension: false,
        );

        $this->assertTrue($detector->isPartial);
        $this->assertNotNull($detector->reason);
        $this->assertStringContainsString('test path', $detector->reason);
        $this->assertStringContainsString('--filter', $detector->reason);
        $this->assertStringContainsString('--group', $detector->reason);
        $this->assertStringContainsString('--testsuite', $detector->reason);
    }

    #[Test]
    public function empty_testsuite_arrays_are_treated_as_no_signal(): void
    {
        // includeTestSuites / excludeTestSuites は array なので, 空配列 = signal 無し。
        $detector = PartialRunDetector::fromSignals(
            hasCliArguments: false,
            hasFilter: false,
            hasExcludeFilter: false,
            hasGroups: false,
            hasExcludeGroups: false,
            includeTestSuites: [],
            excludeTestSuites: [],
            hasTestsCovering: false,
            hasTestsUsing: false,
            hasTestsRequiringPhpExtension: false,
        );

        $this->assertFalse($detector->isPartial);
    }
}
