<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit\Fuzz;

use GuzzleHttp\Psr7\Response;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use Studio\Gesso\Fuzz\ExploredCase;
use Studio\Gesso\Fuzz\OpenApiSpecExploration;
use Studio\Gesso\Fuzz\OpenApiSpecExplorer;
use Studio\Gesso\Spec\OpenApiSpecLoader;

use function count;
use function is_array;
use function is_string;
use function json_encode;
use function preg_match;

final class OpenApiSpecExplorationLinksTest extends TestCase
{
    /** @var list<string> */
    private array $dispatched = [];

    /** @var array<int, string> */
    private array $pets = [];
    private bool $deleteIsNoOp = false;
    private bool $createReturnsUnknownId = false;

    protected function setUp(): void
    {
        OpenApiSpecLoader::reset();
        OpenApiSpecLoader::configure(__DIR__ . '/../../fixtures/specs');
    }

    protected function tearDown(): void
    {
        OpenApiSpecLoader::reset();
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideMalformed_links_fail_loudlyCases(): iterable
    {
        yield 'both targets' => ['both', "Link 'Both' must set exactly one of operationId or operationRef."];
        yield 'missing response value' => ['missingValue', "Link 'Missing' path.id: Runtime expression '\$response.body#/missing'"];
        yield 'unknown operationId' => ['unknown', "Link 'Unknown' names an unknown operationId"];
        yield 'external operationRef' => ['external', "Link 'External': external operationRef is not supported"];
        yield 'undispatchable method' => ['options', "Link 'Options': target method OPTIONS is not dispatchable"];
        yield 'ambiguous bare parameter' => ['ambiguous', "Link 'Ambiguous': parameter 'id' is ambiguous (path, query)"];
    }

    #[Test]
    public function explicit_links_run_seeded_chains(): void
    {
        $summary = $this->plan(maxDepth: 3)->assertResponses();

        $this->assertSame(['POST /pets', 'GET /pets/1', 'DELETE /pets/1', 'GET /pets/1', 'DELETE /pets/1'], $this->dispatched);
        $this->assertSame(4, $summary->followedLinks);
        $this->assertSame(1, $summary->executedCases);
    }

    #[Test]
    public function max_depth_bounds_the_chain(): void
    {
        $summary = $this->plan(maxDepth: 1)->assertResponses();

        $this->assertSame(['POST /pets', 'GET /pets/1', 'DELETE /pets/1'], $this->dispatched);
        $this->assertSame(2, $summary->followedLinks);
    }

    #[Test]
    public function a_readable_resource_after_delete_is_use_after_free(): void
    {
        $this->deleteIsNoOp = true;

        try {
            $this->plan(maxDepth: 3)->assertResponses();
            $this->fail('Expected a use_after_free chain failure.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString("Link chain check 'use_after_free' failed.", $e->getMessage());
            $this->assertStringContainsString('1. POST /pets → 201', $e->getMessage());
            $this->assertStringContainsString('3. [Delete] DELETE /pets/1 → 204', $e->getMessage());
            $this->assertStringContainsString('4. [ReadAfterDelete] GET /pets/1 → 200', $e->getMessage());
        }
    }

    #[Test]
    public function an_unreadable_created_resource_fails_ensure_resource_availability(): void
    {
        $this->createReturnsUnknownId = true;

        try {
            $this->plan(maxDepth: 2)->assertResponses();
            $this->fail('Expected an ensure_resource_availability chain failure.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString("Link chain check 'ensure_resource_availability' failed.", $e->getMessage());
            $this->assertStringContainsString('2. [GetPet] GET /pets/99 → 404', $e->getMessage());
        }
    }

    #[Test]
    public function plans_without_follow_links_dispatch_only_root_cases(): void
    {
        $summary = OpenApiSpecExplorer::explore('links-chain', 1, 7)
            ->includeOperations(['createPet'])
            ->dispatchUsing($this->server(...))
            ->assertResponses();

        $this->assertSame(['POST /pets'], $this->dispatched);
        $this->assertSame(0, $summary->followedLinks);
    }

    #[Test]
    public function negative_case_plans_never_follow_links(): void
    {
        OpenApiSpecExplorer::explore('links-chain', 1, 7)
            ->includeOperations(['createPet'])
            ->negativeCases([4])
            ->dispatchUsing(fn(ExploredCase $case): ResponseInterface => $this->record($case, new Response(422)))
            ->followLinksUsing(static fn(ResponseInterface $response): ResponseInterface => $response)
            ->assertResponses();

        $this->assertCount(1, $this->dispatched);
    }

    #[Test]
    public function specs_without_links_follow_nothing(): void
    {
        $summary = OpenApiSpecExplorer::explore('whole-spec-exploration', 1, 7)
            ->includeOperations(['createPet'])
            ->dispatchUsing(fn(ExploredCase $case): ResponseInterface => $this->record($case, new Response(201, ['Content-Type' => 'application/json'], '{}')))
            ->followLinksUsing(static fn(ResponseInterface $response): ResponseInterface => $response)
            ->assertResponses();

        $this->assertSame(0, $summary->followedLinks);
    }

    #[Test]
    public function chains_replay_deterministically(): void
    {
        $this->plan(maxDepth: 3)->assertResponses();
        $first = $this->dispatched;
        $this->dispatched = [];
        $this->pets = [];
        $this->plan(maxDepth: 3)->assertResponses();

        $this->assertSame($first, $this->dispatched);
    }

    #[Test]
    #[DataProvider('provideMalformed_links_fail_loudlyCases')]
    public function malformed_links_fail_loudly(string $operationId, string $message): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage($message);

        OpenApiSpecExplorer::explore('links-malformed', 1, 7)
            ->includeOperations([$operationId])
            ->dispatchUsing(fn(ExploredCase $case): ResponseInterface => $this->record($case, new Response(201, ['Content-Type' => 'application/json'], '{"id": 1}')))
            ->followLinksUsing(static fn(ResponseInterface $response): ResponseInterface => $response)
            ->assertResponses();
    }

    #[Test]
    public function max_depth_must_be_positive(): void
    {
        $this->expectException(InvalidArgumentException::class);

        OpenApiSpecExplorer::explore('links-chain')->followLinksUsing(static fn(mixed $response): mixed => $response, 0);
    }

    private function plan(int $maxDepth): OpenApiSpecExploration
    {
        return OpenApiSpecExplorer::explore('links-chain', 1, 7)
            ->includeOperations(['createPet'])
            ->dispatchUsing($this->server(...))
            ->followLinksUsing(static fn(ResponseInterface $response): ResponseInterface => $response, $maxDepth);
    }

    private function server(ExploredCase $case): ResponseInterface
    {
        $uri = $case->uri();
        $m = [];
        if ($case->method->value === 'POST' && $uri === '/pets') {
            $id = count($this->pets) + 1;
            $this->pets[$id] = is_array($case->body) && is_string($case->body['name'] ?? null) ? $case->body['name'] : 'pet';
            $returned = $this->createReturnsUnknownId ? 99 : $id;

            return $this->record($case, new Response(201, ['Content-Type' => 'application/json'], (string) json_encode(['id' => $returned, 'name' => $this->pets[$id]])));
        }

        if (preg_match('#^/pets/(\d+)$#', $uri, $m) === 1) {
            $id = (int) $m[1];
            if (!isset($this->pets[$id])) {
                return $this->record($case, new Response(404));
            }

            if ($case->method->value === 'DELETE') {
                if (!$this->deleteIsNoOp) {
                    unset($this->pets[$id]);
                }

                return $this->record($case, new Response(204));
            }

            return $this->record($case, new Response(200, ['Content-Type' => 'application/json'], (string) json_encode(['id' => $id, 'name' => $this->pets[$id]])));
        }

        return $this->record($case, new Response(404));
    }

    private function record(ExploredCase $case, ResponseInterface $response): ResponseInterface
    {
        $this->dispatched[] = $case->method->value . ' ' . $case->uri();

        return $response;
    }
}
