<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Tests\Unit\Validation\Strict;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\OpenApiContractTesting\OpenApiResponseValidator;
use Studio\OpenApiContractTesting\Spec\OpenApiSpecLoader;
use Studio\OpenApiContractTesting\Validation\Strict\StrictRequiredAsserter;
use Studio\OpenApiContractTesting\Validation\Strict\StrictRequiredMode;
use Studio\OpenApiContractTesting\Validation\Strict\StrictRequiredTracker;

class StrictRequiredValidatorIntegrationTest extends TestCase
{
    private OpenApiResponseValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        OpenApiSpecLoader::reset();
        OpenApiSpecLoader::configure(__DIR__ . '/../../../fixtures/specs');
        StrictRequiredTracker::reset();
        $this->validator = new OpenApiResponseValidator();
    }

    protected function tearDown(): void
    {
        StrictRequiredTracker::reset();
        OpenApiSpecLoader::reset();
        parent::tearDown();
    }

    #[Test]
    public function validator_records_observation_on_successful_response(): void
    {
        $result = $this->validator->validate(
            'under-described',
            'PUT',
            '/signed-url',
            200,
            ['expires' => 3600, 'signed_url' => 's3://...', 'url' => 'https://...'],
            'application/json',
        );

        $this->assertTrue($result->isValid());

        $reports = StrictRequiredAsserter::detectAll(StrictRequiredMode::Warn);
        $this->assertCount(1, $reports);
        $this->assertSame(['expires', 'signed_url', 'url'], $reports[0]->missingFromRequired);
        $this->assertSame('PUT', $reports[0]->method);
        $this->assertSame('/signed-url', $reports[0]->path);
    }

    #[Test]
    public function validator_does_not_record_when_body_fails_validation(): void
    {
        // Body lacks "id" (required) — validator returns failure, so the
        // tracker must not record this observation; doing so would poison
        // the intersection for the next legitimate (passing) call.
        $result = $this->validator->validate(
            'under-described',
            'GET',
            '/users/{id}',
            200,
            ['name' => 'alice'],
            'application/json',
        );

        $this->assertFalse($result->isValid());
        $this->assertSame([], StrictRequiredTracker::getObservations('under-described'));
    }

    #[Test]
    public function multiple_passing_observations_intersect_to_always_present_keys(): void
    {
        $this->validator->validate(
            'under-described',
            'GET',
            '/projects/{id}',
            200,
            ['id' => '1', 'name' => 'A', 'created_at' => '2026-01-01T00:00:00Z'],
            'application/json',
        );
        $this->validator->validate(
            'under-described',
            'GET',
            '/projects/{id}',
            200,
            ['id' => '2', 'name' => 'B', 'created_at' => '2026-02-01T00:00:00Z'],
            'application/json',
        );

        $reports = StrictRequiredAsserter::detectAll(StrictRequiredMode::Warn);
        $this->assertCount(1, $reports);
        $this->assertSame(['created_at'], $reports[0]->missingFromRequired);
        $this->assertSame(2, $reports[0]->hits);
    }

    #[Test]
    public function single_call_without_optional_field_collapses_intersection(): void
    {
        $this->validator->validate(
            'under-described',
            'GET',
            '/projects/{id}',
            200,
            ['id' => '1', 'name' => 'A', 'created_at' => '2026-01-01T00:00:00Z'],
            'application/json',
        );
        $this->validator->validate(
            'under-described',
            'GET',
            '/projects/{id}',
            200,
            ['id' => '2', 'name' => 'B'],
            'application/json',
        );

        // Second call omits created_at, so it's no longer "always present" →
        // no under-description drift to report.
        $reports = StrictRequiredAsserter::detectAll(StrictRequiredMode::Warn);
        $this->assertSame([], $reports);
    }
}
