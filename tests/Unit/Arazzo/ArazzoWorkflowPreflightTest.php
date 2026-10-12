<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit\Arazzo;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\Gesso\Arazzo\ArazzoDescription;
use Studio\Gesso\Arazzo\ArazzoDescriptionReason;
use Studio\Gesso\Arazzo\ArazzoSourceResolver;
use Studio\Gesso\Arazzo\ArazzoWorkflowPlan;
use Studio\Gesso\Arazzo\ArazzoWorkflowPreflight;
use Studio\Gesso\Arazzo\InvalidArazzoDescriptionException;
use Studio\Gesso\Coverage\OpenApiCoverageTracker;
use Studio\Gesso\Internal\RuntimeExpressionFamily;
use Studio\Gesso\Spec\OpenApiSpecLoader;

use function array_keys;
use function array_map;
use function implode;

final class ArazzoWorkflowPreflightTest extends TestCase
{
    private const DIR = __DIR__ . '/../../fixtures/arazzo';

    protected function setUp(): void
    {
        OpenApiSpecLoader::reset();
        OpenApiCoverageTracker::reset();
        OpenApiSpecLoader::configure(__DIR__ . '/../../fixtures/specs');
    }

    protected function tearDown(): void
    {
        OpenApiSpecLoader::reset();
        OpenApiCoverageTracker::reset();
    }

    #[Test]
    public function a_valid_workflow_becomes_an_ordered_plan(): void
    {
        $plan = $this->plan('pet-purchase.arazzo.json', 'buyPet');
        $spec = OpenApiSpecLoader::load('arazzo-petstore');

        $this->assertSame(['create', 'read'], array_map(static fn($step): string => $step->stepId, $plan->steps));
        [$create, $read] = $plan->steps;
        $this->assertSame('POST', $create->method);
        $this->assertSame('/pets', $create->pathTemplate);
        $this->assertSame($spec['paths']['/pets']['post'], $create->operation);
        $this->assertTrue($create->hasBody);
        $this->assertSame('application/json', $create->contentType);
        $this->assertSame(['$statusCode == 201'], $create->conditions);
        $this->assertSame(RuntimeExpressionFamily::Response, $create->outputs['petId']->family);

        $this->assertSame('GET', $read->method);
        $this->assertSame('/pets/{petId}', $read->pathTemplate);
        $this->assertSame('getPet', $read->operationId);
        $this->assertSame(['petId', 'X-Request-Id'], array_map(static fn(array $p): string => $p['name'], $read->parameters));
        $this->assertFalse($read->hasBody);
        $this->assertSame(['name'], array_keys($plan->outputs));
        $this->assertSame(['type' => 'object', 'properties' => ['name' => ['type' => 'string']]], $plan->inputsSchema);
        $this->assertFalse(OpenApiCoverageTracker::current()->hasAnyCoverageOn('arazzo-petstore'));
    }

    #[Test]
    public function qualified_operation_ids_and_operation_paths_resolve_across_sources(): void
    {
        $plan = $this->plan('multi-source.arazzo.json', 'qualified');

        $this->assertSame('arazzo-petstore', $plan->steps[0]->specName);
        $this->assertSame('GET', $plan->steps[0]->method);
        $this->assertSame('arazzo-inventory', $plan->steps[1]->specName);
        $this->assertSame('POST', $plan->steps[1]->method);
        $this->assertSame('/stock/{sku}', $plan->steps[1]->pathTemplate);
        // An omitted requestBody.contentType comes from the target operation.
        $this->assertSame('application/vnd.api+json', $plan->steps[1]->contentType);
    }

    #[Test]
    public function bare_operation_ids_need_qualification_with_two_sources(): void
    {
        $e = $this->failure('multi-source.arazzo.json', 'unqualified');

        $this->assertSame(ArazzoDescriptionReason::UnqualifiedOperationId, $e->reason);
    }

    #[Test]
    public function every_unsupported_feature_is_reported_at_once(): void
    {
        $e = $this->failure('unsupported-features.arazzo.json', 'everything');
        $all = implode("\n", $e->problems);

        $this->assertSame(ArazzoDescriptionReason::UnsupportedFeature, $e->reason);
        foreach ([
            '.dependsOn:', 'steps[nested].workflowId:', 'steps[channel].channelPath:', '.onSuccess:', '.onFailure:',
            'reference', 'querystring', 'Selector Object', 'replacements', "'regex'", 'application/xml', '$workflows',
            "step 'later', which has not run yet",
        ] as $token) {
            $this->assertStringContainsString($token, $all);
        }

        $this->assertCount(13, $e->problems, $all);
    }

    #[Test]
    public function a_workflow_parameter_is_checked_per_step_after_overrides(): void
    {
        $plan = $this->plan('preflight-regressions.arazzo.json', 'overriddenWorkflowParameter');

        $this->assertSame('first', $plan->steps[0]->parameters[0]['value']);
        $this->assertSame('$steps.create.outputs.petId', $plan->steps[1]->parameters[0]['value']);
    }

    #[Test]
    public function an_omitted_content_type_must_resolve_to_json(): void
    {
        $e = $this->failure('preflight-regressions.arazzo.json', 'inferredXmlBody');

        $this->assertSame(ArazzoDescriptionReason::UnsupportedFeature, $e->reason);
        $this->assertStringContainsString('application/xml', $e->problems[0]);
    }

    #[Test]
    public function target_problems_are_reported(): void
    {
        $this->assertSame(ArazzoDescriptionReason::AmbiguousTarget, $this->failure('malformed/two-targets.arazzo.json', 'w')->reason);
        $this->assertSame(ArazzoDescriptionReason::UnknownSource, $this->failure('malformed/unknown-source.arazzo.json', 'w')->reason);
    }

    private function plan(string $file, string $workflowId): ArazzoWorkflowPlan
    {
        $description = ArazzoDescription::fromFile(self::DIR . '/' . $file);

        return ArazzoWorkflowPreflight::check($description, $workflowId, ArazzoSourceResolver::resolve($description));
    }

    private function failure(string $file, string $workflowId): InvalidArazzoDescriptionException
    {
        try {
            $this->plan($file, $workflowId);
        } catch (InvalidArazzoDescriptionException $e) {
            return $e;
        }

        $this->fail('Expected InvalidArazzoDescriptionException.');
    }
}
