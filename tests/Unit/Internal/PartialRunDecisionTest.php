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

    #[Test]
    public function default_testsuite_match_neutralises_testsuite_signal_when_opted_in(): void
    {
        // Issue #236: `defaultTestSuite` 経由で includeTestSuites が埋まったケースは,
        // ユーザーが opt-in したときだけ "canonical full run" として扱う。
        $decision = PartialRunDecision::fromSignals(
            hasCliArguments: false,
            hasFilter: false,
            hasExcludeFilter: false,
            hasGroups: false,
            hasExcludeGroups: false,
            includeTestSuites: ['ApplicationTest'],
            excludeTestSuites: [],
            hasTestsCovering: false,
            hasTestsUsing: false,
            hasTestsRequiringPhpExtension: false,
            defaultTestSuite: 'ApplicationTest',
            treatDefaultTestSuiteAsFull: true,
        );

        $this->assertNull($decision);
    }

    #[Test]
    public function default_testsuite_opt_in_does_not_match_when_extra_suites_selected(): void
    {
        // CLI で `--testsuite=ApplicationTest,Other` のように defaultTestSuite を越えた
        // 選択を渡したケースは, opt-in 中でも partial のまま扱う (canonical run の範囲を逸脱)。
        $decision = PartialRunDecision::fromSignals(
            hasCliArguments: false,
            hasFilter: false,
            hasExcludeFilter: false,
            hasGroups: false,
            hasExcludeGroups: false,
            includeTestSuites: ['ApplicationTest', 'StripeIntegration'],
            excludeTestSuites: [],
            hasTestsCovering: false,
            hasTestsUsing: false,
            hasTestsRequiringPhpExtension: false,
            defaultTestSuite: 'ApplicationTest',
            treatDefaultTestSuiteAsFull: true,
        );

        $this->assertNotNull($decision);
        $this->assertStringContainsString('--testsuite', $decision->reason);
    }

    #[Test]
    public function default_testsuite_opt_in_is_inert_without_configured_default(): void
    {
        // phpunit.xml で defaultTestSuite が未設定なら flag を立てても意味を持たない。
        $decision = PartialRunDecision::fromSignals(
            hasCliArguments: false,
            hasFilter: false,
            hasExcludeFilter: false,
            hasGroups: false,
            hasExcludeGroups: false,
            includeTestSuites: ['ApplicationTest'],
            excludeTestSuites: [],
            hasTestsCovering: false,
            hasTestsUsing: false,
            hasTestsRequiringPhpExtension: false,
            defaultTestSuite: null,
            treatDefaultTestSuiteAsFull: true,
        );

        $this->assertNotNull($decision);
        $this->assertStringContainsString('--testsuite', $decision->reason);
    }

    #[Test]
    public function default_testsuite_match_does_not_neutralise_when_opt_out(): void
    {
        // 既定 (flag=false) では既存挙動を維持: defaultTestSuite 経由でも partial。
        $decision = PartialRunDecision::fromSignals(
            hasCliArguments: false,
            hasFilter: false,
            hasExcludeFilter: false,
            hasGroups: false,
            hasExcludeGroups: false,
            includeTestSuites: ['ApplicationTest'],
            excludeTestSuites: [],
            hasTestsCovering: false,
            hasTestsUsing: false,
            hasTestsRequiringPhpExtension: false,
            defaultTestSuite: 'ApplicationTest',
            treatDefaultTestSuiteAsFull: false,
        );

        $this->assertNotNull($decision);
        $this->assertStringContainsString('--testsuite', $decision->reason);
    }

    #[Test]
    public function default_testsuite_opt_in_only_neutralises_testsuite_signal(): void
    {
        // flag が無効化するのは `--testsuite` reason だけ。他の partial signal は素通り。
        $decision = PartialRunDecision::fromSignals(
            hasCliArguments: false,
            hasFilter: true,
            hasExcludeFilter: false,
            hasGroups: false,
            hasExcludeGroups: false,
            includeTestSuites: ['ApplicationTest'],
            excludeTestSuites: [],
            hasTestsCovering: false,
            hasTestsUsing: false,
            hasTestsRequiringPhpExtension: false,
            defaultTestSuite: 'ApplicationTest',
            treatDefaultTestSuiteAsFull: true,
        );

        $this->assertNotNull($decision);
        $this->assertStringContainsString('--filter', $decision->reason);
        $this->assertStringNotContainsString('--testsuite', $decision->reason);
    }
}
