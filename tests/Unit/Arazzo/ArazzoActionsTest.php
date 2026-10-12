<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit\Arazzo;

use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Studio\Gesso\Arazzo\ArazzoDescriptionReason;
use Studio\Gesso\Arazzo\ArazzoFailureKind;
use Studio\Gesso\Arazzo\ArazzoStepRequest;
use Studio\Gesso\Arazzo\ArazzoStepResult;
use Studio\Gesso\Arazzo\ArazzoWorkflowResult;
use Studio\Gesso\Arazzo\ArazzoWorkflowRunner;
use Studio\Gesso\Arazzo\InvalidArazzoDescriptionException;
use Studio\Gesso\Coverage\OpenApiCoverageTracker;
use Studio\Gesso\Spec\OpenApiSpecLoader;
use Studio\Gesso\Validation\Strict\StrictRequiredTracker;

use function array_map;
use function count;
use function implode;
use function is_array;
use function is_string;
use function json_decode;
use function json_encode;
use function preg_match;

final class ArazzoActionsTest extends TestCase
{
    private const DIR = __DIR__ . '/../../fixtures/arazzo';

    /** @var list<string> */
    private array $dispatched = [];

    /** @var array<int, array{id: int, name: string}> */
    private array $pets = [];

    protected function setUp(): void
    {
        OpenApiSpecLoader::reset();
        OpenApiCoverageTracker::reset();
        StrictRequiredTracker::resetCurrent();
        OpenApiSpecLoader::configure(__DIR__ . '/../../fixtures/specs');
    }

    protected function tearDown(): void
    {
        OpenApiSpecLoader::reset();
        OpenApiCoverageTracker::reset();
        StrictRequiredTracker::resetCurrent();
    }

    #[Test]
    public function end_stops_early_and_still_evaluates_workflow_outputs(): void
    {
        $stopped = $this->runWorkflow('endEarly', 'Stop');
        $this->assertTrue($stopped->isSuccessful(), $stopped->failureMessage());
        $this->assertSame(['create'], $this->stepIds($stopped));
        $this->assertSame(['petId' => 1], $stopped->outputs);

        $continued = $this->runWorkflow('endEarly', 'Rex');
        $this->assertTrue($continued->isSuccessful(), $continued->failureMessage());
        $this->assertSame(['create', 'read'], $this->stepIds($continued));
        $this->assertSame('GET /pets/2', $this->dispatched[2], 'the Reusable parameter resolved to the created pet');
    }

    #[Test]
    public function goto_forward_skips_steps(): void
    {
        $result = $this->runWorkflow('skipForward', 'Rex');

        $this->assertTrue($result->isSuccessful(), $result->failureMessage());
        $this->assertSame(['create', 'list'], $this->stepIds($result));
        $this->assertSame(['POST /pets', 'GET /pets'], $this->dispatched);
    }

    #[Test]
    public function a_guarded_backwards_goto_re_executes_steps(): void
    {
        $result = $this->runWorkflow('loopBack', 'Rex');

        $this->assertTrue($result->isSuccessful(), $result->failureMessage());
        $this->assertSame(['create', 'read', 'create', 'read'], $this->stepIds($result));
        $this->assertSame(['POST /pets', 'GET /pets/1', 'POST /pets', 'GET /pets/2'], $this->dispatched);
    }

    #[Test]
    public function routing_around_a_failure_never_turns_the_run_green(): void
    {
        $result = $this->runWorkflow('routeAroundFailure', 'Rex');

        $this->assertFalse($result->isSuccessful());
        $this->assertSame(['read', 'list'], $this->stepIds($result));
        $this->assertSame(ArazzoFailureKind::Criterion, $result->failure?->kind);
        $this->assertSame('read', $result->failure->stepId);
        $this->assertSame($result->failure, $result->steps[0]->failure);
        $this->assertNull($result->steps[1]->failure);
        $this->assertSame([], $result->outputs);
        $this->assertStringContainsString("failed at step 'read' (criterion)", $result->failureMessage());
    }

    #[Test]
    public function a_reusable_end_action_stops_after_a_failure(): void
    {
        $result = $this->runWorkflow('abortViaComponent', 'Rex');

        $this->assertSame(ArazzoFailureKind::Criterion, $result->failure?->kind);
        $this->assertSame(['read'], $this->stepIds($result));
        $this->assertSame(['GET /pets/999'], $this->dispatched);
    }

    #[Test]
    public function an_unconditional_loop_hits_the_execution_budget(): void
    {
        $result = ArazzoWorkflowRunner::workflow(self::DIR . '/actions-loop.arazzo.json', 'forever')
            ->dispatchUsing($this->server(...))
            ->run();

        $this->assertSame(ArazzoFailureKind::Workflow, $result->failure?->kind);
        $this->assertNull($result->failure->stepId);
        $this->assertStringContainsString('budget exhausted', $result->failure->message);
        $this->assertCount(1000, $this->dispatched);
    }

    #[Test]
    public function unsupported_actions_surface_in_one_preflight_exception(): void
    {
        try {
            ArazzoWorkflowRunner::workflow(self::DIR . '/actions-unsupported.arazzo.json', 'unsupported')
                ->dispatchUsing($this->server(...))
                ->run();
            $this->fail('Expected InvalidArazzoDescriptionException.');
        } catch (InvalidArazzoDescriptionException $e) {
            $all = implode("\n", $e->problems);
            $this->assertStringContainsString("onSuccess[0].workflowId: 'workflowId' is not supported yet", $all);
            $this->assertStringContainsString("'regex' criteria are not supported yet", $all);
            $this->assertStringContainsString("'retry' actions are not supported yet", $all);
            $this->assertStringContainsString("'\$components.failureActions.missing' names no component", $all);
            $this->assertStringContainsString("'missing' names no step", $all);
            $this->assertContains($e->reason, [ArazzoDescriptionReason::UnsupportedFeature, ArazzoDescriptionReason::UnknownComponent]);
        }

        $this->assertSame([], $this->dispatched);
    }

    private function runWorkflow(string $workflowId, string $name): ArazzoWorkflowResult
    {
        return ArazzoWorkflowRunner::workflow(self::DIR . '/actions.arazzo.json', $workflowId)
            ->inputs(['name' => $name])
            ->dispatchUsing($this->server(...))
            ->run();
    }

    /**
     * @return list<string>
     */
    private function stepIds(ArazzoWorkflowResult $result): array
    {
        return array_map(static fn(ArazzoStepResult $step): string => $step->stepId, $result->steps);
    }

    private function server(ArazzoStepRequest $request): ResponseInterface
    {
        $uri = $request->uri();
        $this->dispatched[] = $request->method . ' ' . $uri;
        $m = [];
        if ($request->method === 'POST' && $uri === '/pets') {
            $id = count($this->pets) + 1;
            $body = json_decode((string) $request->bodyAsJson(), true);
            $this->pets[$id] = ['id' => $id, 'name' => is_array($body) && is_string($body['name'] ?? null) ? $body['name'] : ''];

            return new Response(201, ['Content-Type' => 'application/json'], (string) json_encode($this->pets[$id]));
        }

        if ($request->method === 'GET' && $uri === '/pets') {
            return new Response(200, ['Content-Type' => 'application/json'], '[]');
        }

        if ($request->method === 'GET' && preg_match('#^/pets/(\d+)$#', $uri, $m) === 1 && isset($this->pets[(int) $m[1]])) {
            return new Response(200, ['Content-Type' => 'application/json'], (string) json_encode($this->pets[(int) $m[1]]));
        }

        return new Response(404);
    }
}
