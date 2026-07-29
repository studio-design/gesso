<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit\Fuzz;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Studio\Gesso\Fuzz\ContractCheck;
use Studio\Gesso\Fuzz\ExploredCase;
use Studio\Gesso\Fuzz\ExploredOperation;
use Studio\Gesso\Fuzz\OpenApiContractChecks;
use Studio\Gesso\Spec\OpenApiSpecLoader;

class OpenApiContractChecksTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        OpenApiSpecLoader::reset();
        OpenApiSpecLoader::configure(__DIR__ . '/../../fixtures/specs');
    }

    protected function tearDown(): void
    {
        OpenApiSpecLoader::reset();
        parent::tearDown();
    }

    #[Test]
    public function collects_a_failure_instead_of_throwing_when_the_probe_is_accepted(): void
    {
        $dispatched = [];

        $summary = OpenApiContractChecks::run('contract-checks', seed: 7)
            ->checks([ContractCheck::UnsupportedMethod])
            ->includePaths(['/pets'])
            ->dispatchUsing(static function (ExploredCase $case) use (&$dispatched): int {
                $dispatched[] = $case;

                return 200; // API wrongly accepts the undocumented method
            })
            ->report();

        $this->assertCount(1, $dispatched);
        $this->assertNotContains($dispatched[0]->method->value, ['GET', 'POST'], 'probe must use an undocumented method');
        $this->assertNull($dispatched[0]->body);
        $this->assertSame([], $dispatched[0]->query);
        $this->assertSame('/pets', $dispatched[0]->matchedPath);

        $this->assertCount(1, $summary->failures);
        $failure = $summary->failures[0];
        $this->assertSame(ContractCheck::UnsupportedMethod, $failure->check);
        $this->assertSame('/pets', $failure->path);
        $this->assertSame([405], $failure->expectedStatuses);
        $this->assertSame(200, $failure->actualStatus);
        $this->assertSame(1, $summary->probedPaths);
        $this->assertSame(1, $summary->dispatchedProbes);
    }

    #[Test]
    public function passes_when_the_probe_is_rejected_with_405(): void
    {
        $summary = OpenApiContractChecks::run('contract-checks', seed: 7)
            ->checks([ContractCheck::UnsupportedMethod])
            ->includePaths(['/pets'])
            ->dispatchUsing(static fn(ExploredCase $case): int => 405)
            ->report();

        $this->assertFalse($summary->hasFailures());
        $this->assertSame('', $summary->describeFailures());
    }

    #[Test]
    public function expected_statuses_override_replaces_the_default(): void
    {
        $summary = OpenApiContractChecks::run('contract-checks', seed: 7)
            ->checks([ContractCheck::UnsupportedMethod])
            ->includePaths(['/pets'])
            ->expectedStatuses(ContractCheck::UnsupportedMethod, [405, 404])
            ->dispatchUsing(static fn(ExploredCase $case): int => 404)
            ->report();

        $this->assertFalse($summary->hasFailures());
    }

    #[Test]
    public function substitutes_generated_path_parameters(): void
    {
        $uris = [];

        OpenApiContractChecks::run('contract-checks', seed: 3)
            ->checks([ContractCheck::UnsupportedMethod])
            ->includePaths(['/pets/{petId}'])
            ->dispatchUsing(static function (ExploredCase $case) use (&$uris): int {
                $uris[] = $case->uri();

                return 405;
            })
            ->report();

        $this->assertCount(1, $uris);
        $this->assertStringNotContainsString('{petId}', $uris[0]);
    }

    #[Test]
    public function hooks_run_in_order_and_tear_down_runs_on_dispatch_failure(): void
    {
        $events = [];

        try {
            OpenApiContractChecks::run('contract-checks', seed: 7)
                ->checks([ContractCheck::UnsupportedMethod])
                ->includePaths(['/pets'])
                ->setUpUsing(static function (ExploredOperation $operation) use (&$events): void {
                    $events[] = 'setUp';
                })
                ->authenticateUsing(static function (ExploredOperation $operation) use (&$events): void {
                    $events[] = 'auth';
                })
                ->tearDownUsing(static function (ExploredOperation $operation) use (&$events): void {
                    $events[] = 'tearDown';
                })
                ->dispatchUsing(static function (ExploredCase $case) use (&$events): int {
                    $events[] = 'dispatch';

                    throw new RuntimeException('boom');
                })
                ->report();
            $this->fail('Expected the dispatch failure to be rethrown.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Contract check dispatch failed.', $e->getMessage());
            $this->assertStringContainsString('unsupported_method', $e->getMessage());
            $this->assertStringContainsString('Curl:', $e->getMessage());
        }

        $this->assertSame(['setUp', 'auth', 'dispatch', 'tearDown'], $events);
    }

    #[Test]
    public function loud_failures_for_missing_configuration(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/requires checks\(\)/');
        OpenApiContractChecks::run('contract-checks')
            ->dispatchUsing(static fn(ExploredCase $case): int => 405)
            ->report();
    }

    #[Test]
    public function checks_rejects_an_empty_list(): void
    {
        $this->expectException(InvalidArgumentException::class);
        OpenApiContractChecks::run('contract-checks')->checks([]);
    }

    #[Test]
    public function report_requires_dispatch_using(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/requires dispatchUsing\(\)/');
        OpenApiContractChecks::run('contract-checks')
            ->checks([ContractCheck::UnsupportedMethod])
            ->report();
    }

    #[Test]
    public function expected_statuses_validates_range_and_emptiness(): void
    {
        $plan = OpenApiContractChecks::run('contract-checks');

        $this->expectException(InvalidArgumentException::class);
        $plan->expectedStatuses(ContractCheck::UnsupportedMethod, [99]);
    }

    #[Test]
    public function run_rejects_an_empty_spec_name(): void
    {
        $this->expectException(InvalidArgumentException::class);
        OpenApiContractChecks::run('');
    }

    #[Test]
    public function filters_matching_nothing_fail_loudly(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/matched no operations/');
        OpenApiContractChecks::run('contract-checks')
            ->checks([ContractCheck::UnsupportedMethod])
            ->includeTags(['nope'])
            ->dispatchUsing(static fn(ExploredCase $case): int => 405)
            ->report();
    }

    #[Test]
    public function a_fully_documented_path_is_skipped_with_a_reason(): void
    {
        $dispatched = 0;

        $summary = OpenApiContractChecks::run('contract-checks', seed: 7)
            ->checks([ContractCheck::UnsupportedMethod])
            ->includePaths(['/saturated'])
            ->dispatchUsing(static function (ExploredCase $case) use (&$dispatched): int {
                $dispatched++;

                return 405;
            })
            ->report();

        $this->assertSame(0, $dispatched);
        $this->assertCount(1, $summary->skips);
        $this->assertSame('/saturated', $summary->skips[0]->path);
        $this->assertNull($summary->skips[0]->method);
        $this->assertStringContainsString('Every explorable HTTP method is documented', $summary->skips[0]->reason);
    }

    #[Test]
    public function a_custom_method_only_path_is_skipped_not_probed(): void
    {
        $summary = OpenApiContractChecks::run('contract-checks', seed: 7)
            ->checks([ContractCheck::UnsupportedMethod])
            ->includePaths(['/custom-only'])
            ->dispatchUsing(static fn(ExploredCase $case): int => 405)
            ->report();

        $this->assertSame(0, $summary->dispatchedProbes);
        $this->assertCount(1, $summary->skips);
        $this->assertStringContainsString('explorer-supported method', $summary->skips[0]->reason);
    }

    #[Test]
    public function probe_method_is_deterministic_for_a_seed_and_undocumented(): void
    {
        $probeFor = static function (int $seed): string {
            $method = null;
            OpenApiContractChecks::run('contract-checks', seed: $seed)
                ->checks([ContractCheck::UnsupportedMethod])
                ->includePaths(['/pets'])
                ->dispatchUsing(static function (ExploredCase $case) use (&$method): int {
                    $method = $case->method->value;

                    return 405;
                })
                ->report();

            return $method ?? self::fail('probe did not dispatch');
        };

        $first = $probeFor(7);
        $this->assertSame($first, $probeFor(7), 'same seed must choose the same probe method');
        $this->assertContains($first, ['PUT', 'PATCH', 'DELETE', 'QUERY']);
        // Pinned regression: fill in the literal observed on first green run.
        $this->assertSame('PUT', $first);
    }
}
