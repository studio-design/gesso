<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit\Arazzo;

use GuzzleHttp\Psr7\Response;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use Studio\Gesso\Arazzo\ArazzoDescriptionReason;
use Studio\Gesso\Arazzo\ArazzoFailureKind;
use Studio\Gesso\Arazzo\ArazzoStepRequest;
use Studio\Gesso\Arazzo\ArazzoWorkflowResult;
use Studio\Gesso\Arazzo\ArazzoWorkflowRunner;
use Studio\Gesso\Arazzo\InvalidArazzoDescriptionException;
use Studio\Gesso\Coverage\OpenApiCoverageTracker;
use Studio\Gesso\Spec\OpenApiSpecLoader;
use Studio\Gesso\Validation\Strict\StrictRequiredTracker;

use function array_keys;
use function count;
use function is_array;
use function is_string;
use function json_decode;
use function json_encode;
use function preg_match;

final class ArazzoWorkflowRunnerTest extends TestCase
{
    private const DIR = __DIR__ . '/../../fixtures/arazzo';

    /** @var list<string> */
    private array $dispatched = [];

    /** @var list<ArazzoStepRequest> */
    private array $requests = [];

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

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideA_created_resource_flows_into_the_next_stepCases(): iterable
    {
        yield 'json' => ['pet-purchase.arazzo.json'];
        yield 'yaml' => ['pet-purchase.arazzo.yaml'];
    }

    #[Test]
    #[DataProvider('provideA_created_resource_flows_into_the_next_stepCases')]
    public function a_created_resource_flows_into_the_next_step(string $file): void
    {
        $result = ArazzoWorkflowRunner::workflow(self::DIR . '/' . $file, 'buyPet')
            ->inputs(['name' => 'Rex'])
            ->dispatchUsing($this->server(...))
            ->run();

        $this->assertTrue($result->isSuccessful(), $result->failureMessage());
        $this->assertSame('', $result->failureMessage());
        $this->assertSame(['POST /pets', 'GET /pets/1'], $this->dispatched);
        $this->assertSame('req-1', $this->requests[1]->headers['X-Request-Id']);
        $this->assertSame(['petId' => 1], $this->requests[1]->pathParams);
        $this->assertSame('Rex', $result->outputs['name']);
        $this->assertCount(2, $result->steps);
        foreach ($result->steps as $step) {
            $this->assertTrue($step->requestValidation->isValid(), $step->requestValidation->errorMessage());
            $this->assertNotNull($step->responseValidation);
            $this->assertTrue($step->responseValidation->isValid(), $step->responseValidation->errorMessage());
            $this->assertNull($step->failure);
        }
        $this->assertSame(1, $result->steps[0]->outputs['petId']);
        $this->assertSame([['condition' => '$statusCode == 201', 'passed' => true, 'error' => null]], $result->steps[0]->criteria);

        $covered = array_keys(OpenApiCoverageTracker::current()->getCoveredOn()['arazzo-petstore'] ?? []);
        $this->assertContains('POST /pets', $covered);
        $this->assertContains('GET /pets/{petId}', $covered);
    }

    #[Test]
    public function an_invalid_authored_request_is_never_dispatched(): void
    {
        $result = ArazzoWorkflowRunner::workflow(self::DIR . '/invalid-authored-request.arazzo.json', 'badRequest')
            ->dispatchUsing($this->server(...))
            ->run();

        $this->assertSame(ArazzoFailureKind::RequestContract, $result->failure?->kind);
        $this->assertSame('create', $result->failure->stepId);
        $this->assertSame([], $this->dispatched);
        $this->assertNull($result->steps[0]->statusCode);
        $this->assertStringContainsString("failed at step 'create' (request_contract)", $result->failureMessage());
    }

    #[Test]
    public function a_throwing_dispatcher_is_a_dispatch_failure(): void
    {
        $result = $this->buyPet(static fn(): never => throw new RuntimeException('connection refused'));

        $this->assertSame(ArazzoFailureKind::Dispatch, $result->failure?->kind);
        $this->assertSame(['RuntimeException: connection refused'], $result->failure->errors);
    }

    #[Test]
    public function a_response_of_the_wrong_shape_is_a_dispatch_failure(): void
    {
        $result = $this->buyPet(static fn(): string => 'not a response');

        $this->assertSame(ArazzoFailureKind::Dispatch, $result->failure?->kind);
    }

    #[Test]
    public function a_contract_violating_response_keeps_its_status_and_body(): void
    {
        $result = $this->buyPet(static fn(): ResponseInterface => new Response(201, ['Content-Type' => 'application/json'], '{"id": "nope", "name": "Rex"}'));

        $this->assertSame(ArazzoFailureKind::ResponseContract, $result->failure?->kind);
        $this->assertSame(201, $result->steps[0]->statusCode);
        $this->assertNotNull($result->steps[0]->responseBody);
        $this->assertTrue($result->steps[0]->responseBody->present);
        $this->assertNotSame([], $result->failure->errors);
    }

    #[Test]
    public function a_false_success_criterion_is_a_criterion_failure(): void
    {
        $criterion = ArazzoWorkflowRunner::workflow(self::DIR . '/pet-purchase.arazzo.json', 'buyPet')
            ->inputs(['name' => 'Rex'])
            ->dispatchUsing(fn(ArazzoStepRequest $request): array|ResponseInterface => $request->method === 'GET'
                ? ['status' => 200, 'headers' => ['Content-Type' => 'application/json'], 'body' => '{"id": 1, "name": "Max"}']
                : $this->server($request))
            ->run();

        $this->assertSame(ArazzoFailureKind::Criterion, $criterion->failure?->kind);
        $this->assertSame('read', $criterion->failure->stepId);
        $this->assertSame(['$response.body#/name == $inputs.name'], $criterion->failure->errors);
        $this->assertSame([], $criterion->outputs);
        $this->assertStringContainsString('Completed steps: create (POST /pets → 201), read (GET /pets/{petId} → 200)', $criterion->failureMessage());
    }

    #[Test]
    public function missing_required_inputs_fail_before_dispatch(): void
    {
        $result = ArazzoWorkflowRunner::workflow(self::DIR . '/inputs-required.arazzo.json', 'needsName')
            ->dispatchUsing($this->server(...))
            ->run();

        $this->assertSame(ArazzoFailureKind::Workflow, $result->failure?->kind);
        $this->assertNull($result->failure->stepId);
        $this->assertSame([], $result->steps);
        $this->assertSame([], $this->dispatched);
    }

    #[Test]
    public function preflight_failures_propagate_with_nothing_dispatched(): void
    {
        try {
            ArazzoWorkflowRunner::workflow(self::DIR . '/bad-condition.arazzo.json', 'bad')
                ->dispatchUsing($this->server(...))
                ->run();
            $this->fail('Expected InvalidArazzoDescriptionException.');
        } catch (InvalidArazzoDescriptionException $e) {
            $this->assertSame(ArazzoDescriptionReason::UnsupportedFeature, $e->reason);
            $this->assertCount(5, $e->problems, $e->getMessage());
        }

        $this->assertSame([], $this->dispatched);
    }

    #[Test]
    public function a_request_path_matching_another_operation_is_not_dispatched(): void
    {
        $result = ArazzoWorkflowRunner::workflow(self::DIR . '/runner-regressions.arazzo.json', 'emptyPathParameter')
            ->dispatchUsing($this->server(...))
            ->run();

        $this->assertSame(ArazzoFailureKind::RequestContract, $result->failure?->kind);
        $this->assertSame([], $this->dispatched);
    }

    #[Test]
    public function a_malformed_inputs_schema_is_a_description_problem(): void
    {
        try {
            ArazzoWorkflowRunner::workflow(self::DIR . '/runner-regressions.arazzo.json', 'malformedInputsSchema')
                ->dispatchUsing($this->server(...))
                ->run();
            $this->fail('Expected InvalidArazzoDescriptionException.');
        } catch (InvalidArazzoDescriptionException $e) {
            $this->assertSame(ArazzoDescriptionReason::InvalidField, $e->reason);
        }

        $this->assertSame([], $this->dispatched);
    }

    #[Test]
    public function a_null_parameter_value_is_not_sent(): void
    {
        $result = ArazzoWorkflowRunner::workflow(self::DIR . '/runner-regressions.arazzo.json', 'nullQueryValue')
            ->inputs(['limit' => null])
            ->dispatchUsing(fn(ArazzoStepRequest $request): array => ['status' => 200, 'headers' => ['Content-Type' => 'application/json'], 'body' => '[]'])
            ->run();

        $this->assertTrue($result->isSuccessful(), $result->failureMessage());
        $this->assertSame([], $result->steps[0]->request->query);
        $this->assertSame('/pets', $result->steps[0]->request->uri());
    }

    #[Test]
    public function run_requires_a_dispatcher(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ArazzoWorkflowRunner::workflow(self::DIR . '/pet-purchase.arazzo.json', 'buyPet')->run();
    }

    #[Test]
    public function a_second_run_reuses_the_loaded_sources(): void
    {
        $execution = ArazzoWorkflowRunner::workflow(self::DIR . '/pet-purchase.arazzo.json', 'buyPet')
            ->inputs(['name' => 'Rex'])
            ->dispatchUsing($this->server(...));

        $this->assertTrue($execution->run()->isSuccessful());
        $this->assertTrue($execution->run()->isSuccessful());
        $this->assertCount(4, $this->dispatched);
    }

    /**
     * @param callable(ArazzoStepRequest): mixed $dispatch
     */
    private function buyPet(callable $dispatch): ArazzoWorkflowResult
    {
        return ArazzoWorkflowRunner::workflow(self::DIR . '/pet-purchase.arazzo.json', 'buyPet')
            ->inputs(['name' => 'Rex'])
            ->dispatchUsing($dispatch)
            ->run();
    }

    private function server(ArazzoStepRequest $request): ResponseInterface
    {
        $this->dispatched[] = $request->method . ' ' . $request->uri();
        $this->requests[] = $request;
        $m = [];
        if ($request->method === 'POST' && $request->uri() === '/pets') {
            $id = count($this->pets) + 1;
            $body = json_decode((string) $request->bodyAsJson(), true);
            $this->pets[$id] = ['id' => $id, 'name' => is_array($body) && is_string($body['name'] ?? null) ? $body['name'] : ''];

            return new Response(201, ['Content-Type' => 'application/json'], (string) json_encode($this->pets[$id]));
        }

        if ($request->method === 'GET' && preg_match('#^/pets/(\d+)$#', $request->uri(), $m) === 1 && isset($this->pets[(int) $m[1]])) {
            return new Response(200, ['Content-Type' => 'application/json'], (string) json_encode($this->pets[(int) $m[1]]));
        }

        return new Response(404);
    }
}
