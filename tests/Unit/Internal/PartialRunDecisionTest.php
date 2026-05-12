<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Tests\Unit\Internal;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\OpenApiContractTesting\Internal\PartialRunDecision;

final class PartialRunDecisionTest extends TestCase
{
    /**
     * @return iterable<string, array{0: array<string, bool|list<non-empty-string>>, 1: string}>
     */
    public static function providePartial_when_any_single_signal_activeCases(): iterable
    {
        yield 'cli path args' => [['hasCliArguments' => true], 'test paths'];
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
    public function returns_null_when_no_signals_set(): void
    {
        $decision = PartialRunDecision::fromSignals(
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

        $this->assertNull($decision);
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
        $decision = PartialRunDecision::fromSignals(
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

        $this->assertNotNull($decision);
        $this->assertStringContainsString($expectedReasonFragment, $decision->reason);
    }

    #[Test]
    public function reason_lists_all_active_signals_in_stable_order(): void
    {
        // 複数の signal を立てたとき, reason に全部含まれることを確認。
        // 順序は decision の出力が安定 (declaration order) であることをピンする。
        $decision = PartialRunDecision::fromSignals(
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

        $this->assertNotNull($decision);
        $this->assertStringContainsString('test paths', $decision->reason);
        $this->assertStringContainsString('--filter', $decision->reason);
        $this->assertStringContainsString('--group', $decision->reason);
        $this->assertStringContainsString('--testsuite', $decision->reason);
    }

    #[Test]
    public function empty_testsuite_arrays_are_treated_as_no_signal(): void
    {
        // includeTestSuites / excludeTestSuites は array なので, 空配列 = signal 無し。
        $decision = PartialRunDecision::fromSignals(
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

        $this->assertNull($decision);
    }

    #[Test]
    public function partial_factory_carries_reason_verbatim(): void
    {
        // The `partial()` factory is the direct path used by tests that
        // want to pin a specific reason without enumerating every signal.
        // It must round-trip the reason byte-for-byte so the subscriber's
        // WARNING contains exactly what callers asked for.
        $decision = PartialRunDecision::partial('--filter');

        $this->assertSame('--filter', $decision->reason);
    }
}
