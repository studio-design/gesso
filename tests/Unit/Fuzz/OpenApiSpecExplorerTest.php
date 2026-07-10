<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Tests\Unit\Fuzz;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Studio\OpenApiContractTesting\Fuzz\ExplorationSkip;
use Studio\OpenApiContractTesting\Fuzz\ExploredCase;
use Studio\OpenApiContractTesting\Fuzz\ExploredOperation;
use Studio\OpenApiContractTesting\Fuzz\OpenApiSpecExplorer;
use Studio\OpenApiContractTesting\Spec\OpenApiSpecLoader;

use function array_filter;
use function array_map;
use function sort;
use function str_contains;

class OpenApiSpecExplorerTest extends TestCase
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
    public function explores_every_selected_supported_operation(): void
    {
        $seen = [];

        $summary = OpenApiSpecExplorer::explore('whole-spec-exploration', casesPerOperation: 2, seed: 41)
            ->includeMethods(['get', 'POST'])
            ->dispatchUsing(static function (ExploredCase $case, ExploredOperation $operation) use (&$seen): ExploredCase {
                $seen[] = $operation->method . ' ' . $operation->path;

                return $case;
            })
            ->assertResponseUsing(function (mixed $response): void {
                $this->assertInstanceOf(ExploredCase::class, $response);
            })
            ->assertResponses();

        $this->assertSame(2, $summary->executedOperations);
        $this->assertSame(4, $summary->executedCases);
        $this->assertFalse($summary->hasSkips());
        $this->assertSame(['GET /pets', 'POST /pets'], array_map(
            static fn(ExploredOperation $operation): string => $operation->coverageKey(),
            $summary->operations,
        ));
        $this->assertSame(['GET /pets', 'GET /pets', 'POST /pets', 'POST /pets'], $seen);
    }

    #[Test]
    public function filters_by_tag_path_operation_and_deprecated_flag(): void
    {
        $seen = [];

        $summary = OpenApiSpecExplorer::explore('whole-spec-exploration', casesPerOperation: 1)
            ->includeDeprecated()
            ->includeTags(['admin', 'public'])
            ->excludePaths(['/pets'])
            ->includeOperations(['adminIndex'])
            ->dispatchUsing(static function (ExploredCase $case, ExploredOperation $operation) use (&$seen): null {
                $seen[] = $operation->operationId;

                return null;
            })
            ->assertResponses();

        $this->assertSame(1, $summary->executedOperations);
        $this->assertSame(['adminIndex'], $seen);
    }

    #[Test]
    public function reports_unsupported_and_ungeneratable_operations_as_skipped(): void
    {
        $summary = OpenApiSpecExplorer::explore('whole-spec-exploration', casesPerOperation: 1)
            ->includeMethods(['PUT', 'HEAD', 'COPY'])
            ->dispatchUsing(static fn(): null => null)
            ->assertResponses();

        $this->assertSame(0, $summary->executedOperations);
        $this->assertSame(0, $summary->executedCases);
        $this->assertCount(3, $summary->skips);
        $this->assertSame(['brokenUpdate', 'copyPets', 'headPets'], $this->sortedSkippedOperationIds($summary->skips));
        $reasons = array_map(static fn(ExplorationSkip $skip): string => $skip->reason, $summary->skips);
        $this->assertTrue((bool) array_filter(
            $reasons,
            static fn(string $reason): bool => str_contains($reason, 'requestBody.required: true'),
        ));
    }

    #[Test]
    public function custom_method_filters_are_case_sensitive(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('matched no operations');

        OpenApiSpecExplorer::explore('whole-spec-exploration')
            ->includeMethods(['copy'])
            ->dispatchUsing(static fn(): null => null)
            ->assertResponses();
    }

    #[Test]
    public function rejects_malformed_paths_root_before_dispatch(): void
    {
        $dispatched = false;

        try {
            OpenApiSpecExplorer::explore('malformed-paths')
                ->dispatchUsing(static function () use (&$dispatched): null {
                    $dispatched = true;

                    return null;
                })
                ->assertResponses();
            $this->fail('Expected malformed paths to fail.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString("Malformed 'paths'", $e->getMessage());
            $this->assertStringContainsString('expected object, got string', $e->getMessage());
            $this->assertFalse($dispatched);
        }
    }

    #[Test]
    public function rejects_malformed_path_item_before_dispatching_valid_siblings(): void
    {
        $dispatched = false;

        try {
            OpenApiSpecExplorer::explore('whole-spec-malformed-path-item')
                ->dispatchUsing(static function () use (&$dispatched): null {
                    $dispatched = true;

                    return null;
                })
                ->assertResponses();
            $this->fail('Expected malformed Path Item to fail.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString("Malformed 'paths[\"/broken\"]'", $e->getMessage());
            $this->assertStringContainsString('expected object, got string', $e->getMessage());
            $this->assertFalse($dispatched, 'Structural preflight must run before the valid /ok operation.');
        }
    }

    #[Test]
    public function rejects_malformed_operation_as_spec_failure_instead_of_skip(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Malformed 'paths[\"/scalar-operation\"].get'");

        OpenApiSpecExplorer::explore('whole-spec-malformed-operation')
            ->dispatchUsing(static fn(): null => null)
            ->assertResponses();
    }

    #[Test]
    public function same_global_seed_replays_identical_cases(): void
    {
        $runs = [];

        for ($i = 0; $i < 2; $i++) {
            $generated = [];
            OpenApiSpecExplorer::explore('whole-spec-exploration', casesPerOperation: 3, seed: 99)
                ->includeOperations(['createPet'])
                ->dispatchUsing(static function (ExploredCase $case, ExploredOperation $operation) use (&$generated): null {
                    $generated[] = [$operation->seed, $case->body];

                    return null;
                })
                ->assertResponses();
            $runs[] = $generated;
        }

        $this->assertSame($runs[0], $runs[1]);
    }

    #[Test]
    public function hooks_can_authenticate_mutate_and_clean_up_each_operation(): void
    {
        $events = [];

        OpenApiSpecExplorer::explore('whole-spec-exploration', casesPerOperation: 1)
            ->includeOperations(['createPet'])
            ->setUpUsing(static function () use (&$events): void {
                $events[] = 'setup';
            })
            ->authenticateUsing(static function () use (&$events): void {
                $events[] = 'auth';
            })
            ->mutateCasesUsing(static function (ExploredCase $case) use (&$events): ExploredCase {
                $events[] = 'mutate';

                return $case->withHeaders(['Authorization' => 'Bearer test']);
            })
            ->dispatchUsing(static function (ExploredCase $case) use (&$events): null {
                $events[] = $case->headers['Authorization'];

                return null;
            })
            ->tearDownUsing(static function () use (&$events): void {
                $events[] = 'teardown';
            })
            ->assertResponses();

        $this->assertSame(['setup', 'auth', 'mutate', 'Bearer test', 'teardown'], $events);
    }

    #[Test]
    public function failure_contains_replay_identity_and_previous_exception(): void
    {
        try {
            OpenApiSpecExplorer::explore('whole-spec-exploration', casesPerOperation: 1, seed: 77)
                ->includeOperations(['createPet'])
                ->dispatchUsing(static function (): never {
                    throw new RuntimeException('application failure');
                })
                ->assertResponses();
            $this->fail('Expected whole-spec exploration to fail.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Spec: whole-spec-exploration', $e->getMessage());
            $this->assertStringContainsString('Operation: createPet', $e->getMessage());
            $this->assertStringContainsString('Method/path: POST /pets', $e->getMessage());
            $this->assertStringContainsString('Global seed: 77', $e->getMessage());
            $this->assertStringContainsString('OpenApiEndpointExplorer::explore(', $e->getMessage());
            $this->assertSame('application failure', $e->getPrevious()?->getMessage());
        }
    }

    #[Test]
    public function rejects_a_filter_set_that_matches_nothing(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('matched no operations');

        OpenApiSpecExplorer::explore('whole-spec-exploration')
            ->includeOperations(['missing'])
            ->dispatchUsing(static fn(): null => null)
            ->assertResponses();
    }

    /**
     * @param list<ExplorationSkip> $skips
     *
     * @return list<string>
     */
    private function sortedSkippedOperationIds(array $skips): array
    {
        $ids = array_map(static fn(ExplorationSkip $skip): string => (string) $skip->operation->operationId, $skips);
        sort($ids);

        return $ids;
    }
}
