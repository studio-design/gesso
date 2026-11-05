<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit\Contract;

use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\Gesso\Contract\ContractCheck;
use Studio\Gesso\Contract\ContractCheckSkip;
use Studio\Gesso\Contract\OpenApiContractChecks;
use Studio\Gesso\Fuzz\ExplorationCaseKind;
use Studio\Gesso\Fuzz\ExploredCase;
use Studio\Gesso\Spec\OpenApiSpecLoader;
use Symfony\Component\HttpFoundation\Response;

use function array_map;
use function implode;

/** `negative_data_rejection`, `positive_data_acceptance`, and the 405 `Allow` header. */
final class DataContractChecksTest extends TestCase
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
    public function invalid_data_must_be_rejected_once_the_valid_request_succeeds(): void
    {
        $kinds = [];
        $summary = OpenApiContractChecks::run('contract-checks', seed: 7)
            ->checks([ContractCheck::NegativeDataRejection])
            ->includePaths(['/pets'])
            ->dispatchIsolatedUsing(static function (ExploredCase $case) use (&$kinds): int {
                $kinds[] = $case->kind;

                return $case->kind === ExplorationCaseKind::Valid ? 201 : 422;
            })
            ->report();

        $this->assertSame([], $summary->failures, $summary->describeFailures());
        $this->assertSame(ExplorationCaseKind::Valid, $kinds[0]);
        $this->assertContains(ExplorationCaseKind::Invalid, $kinds);
        $this->assertSame(1 + 5, $summary->dispatchedProbes);
    }

    #[Test]
    public function accepted_invalid_data_is_a_failure_naming_the_broken_keyword(): void
    {
        $summary = OpenApiContractChecks::run('contract-checks', seed: 7)
            ->checks([ContractCheck::NegativeDataRejection])
            ->includePaths(['/pets'])
            ->includeMethods(['POST'])
            ->dispatchIsolatedUsing(static fn(ExploredCase $case): int => 201)
            ->report();

        $this->assertNotSame([], $summary->failures);
        $this->assertStringContainsString('negative_data_rejection: POST /pets (createPet) [invalid body', $summary->failures[0]->describe());
        $this->assertStringContainsString('expected 4xx, got 201', $summary->failures[0]->describe());
    }

    #[Test]
    public function invalid_data_probes_need_a_successful_control_and_isolation_for_state_changes(): void
    {
        $summary = OpenApiContractChecks::run('contract-checks', seed: 7)
            ->checks([ContractCheck::NegativeDataRejection])
            ->includePaths(['/pets', '/pets/{petId}'])
            ->dispatchUsing(static fn(ExploredCase $case): int => 401)
            ->report();

        $this->assertSame([], $summary->failures);
        $reasons = array_map(static fn(ContractCheckSkip $skip): string => $skip->method . ' ' . $skip->path . ': ' . $skip->reason, $summary->skips);
        $this->assertStringContainsString('POST /pets: POST changes state', implode("\n", $reasons));
        $this->assertStringContainsString('GET /pets/{petId}: The unmutated valid request answered 401 rather than a 2xx', implode("\n", $reasons));
        $this->assertSame(1, $summary->dispatchedProbes);
    }

    #[Test]
    public function valid_data_must_not_be_rejected(): void
    {
        $run = static fn(int $status) => OpenApiContractChecks::run('contract-checks', seed: 7)
            ->checks([ContractCheck::PositiveDataAcceptance])
            ->includePaths(['/pets'])
            ->includeMethods(['GET'])
            ->dispatchUsing(static fn(ExploredCase $case): int => $status)
            ->report();

        foreach ([200, 204, 302, 401, 403, 404, 409, 429] as $accepted) {
            $this->assertSame([], $run($accepted)->failures, (string) $accepted);
        }
        foreach ([400, 422, 500] as $rejected) {
            $failures = $run($rejected)->failures;
            $this->assertCount(1, $failures, (string) $rejected);
            $this->assertStringContainsString('positive_data_acceptance: GET /pets (listPets) [valid request]', $failures[0]->describe());
        }
    }

    #[Test]
    public function a_405_must_carry_the_allow_header(): void
    {
        $run = static fn(mixed $response) => OpenApiContractChecks::run('contract-checks', seed: 7)
            ->checks([ContractCheck::UnsupportedMethod])
            ->includePaths(['/pets'])
            ->dispatchUsing(static fn(ExploredCase $case): mixed => $response)
            ->report();

        $missing = $run(new Response('', 405))->failures;
        $this->assertCount(1, $missing);
        $this->assertStringContainsString('405 without the Allow header RFC 9110 requires', $missing[0]->describe());
        $this->assertCount(1, $run(new Psr7Response(405))->failures);

        $this->assertSame([], $run(new Response('', 405, ['Allow' => 'GET, POST']))->failures);
        $this->assertSame([], $run(new Psr7Response(405, ['Allow' => 'GET, POST']))->failures);
        $this->assertCount(1, $run(new TestResponse(new Response('', 405)))->failures);
        $this->assertSame([], $run(new TestResponse(new Response('', 405, ['Allow' => 'GET, POST'])))->failures);
        $this->assertSame([], $run(405)->failures);
    }
}
