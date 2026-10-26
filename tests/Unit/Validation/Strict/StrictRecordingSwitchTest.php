<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit\Validation\Strict;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\Gesso\OpenApiResponseValidator;
use Studio\Gesso\Spec\OpenApiSpecLoader;
use Studio\Gesso\Validation\Strict\StrictAdditionalPropertiesPerCallChecker;
use Studio\Gesso\Validation\Strict\StrictAdditionalPropertiesTracker;
use Studio\Gesso\Validation\Strict\StrictRequiredPerCallChecker;
use Studio\Gesso\Validation\Strict\StrictRequiredTracker;

final class StrictRecordingSwitchTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        OpenApiSpecLoader::reset();
        OpenApiSpecLoader::configure(__DIR__ . '/../../../fixtures/specs');
        StrictRequiredTracker::resetCurrent();
        StrictAdditionalPropertiesTracker::resetCurrent();
        StrictRequiredPerCallChecker::reset();
        StrictAdditionalPropertiesPerCallChecker::reset();
    }

    protected function tearDown(): void
    {
        StrictRequiredTracker::resetCurrent();
        StrictAdditionalPropertiesTracker::resetCurrent();
        StrictRequiredPerCallChecker::reset();
        StrictAdditionalPropertiesPerCallChecker::reset();
        OpenApiSpecLoader::reset();
        parent::tearDown();
    }

    #[Test]
    public function trackers_record_by_default(): void
    {
        $this->assertTrue((new StrictRequiredTracker())->isRecording());
        $this->assertTrue((new StrictAdditionalPropertiesTracker())->isRecording());
    }

    #[Test]
    public function stopped_trackers_receive_nothing_from_successful_responses(): void
    {
        [$required, $additional] = $this->validateWithTrackers(recording: false);

        $this->assertSame([], $required->recordedSpecsOn());
        $this->assertSame([], $additional->recordedSpecsOn());
        $this->assertSame(0, $additional->evaluationsOn());
        // A stopped tracker exports the same wire state as a fresh one.
        $this->assertSame((new StrictRequiredTracker())->exportStateOn(), $required->exportStateOn());
        $this->assertSame((new StrictAdditionalPropertiesTracker())->exportStateOn(), $additional->exportStateOn());
    }

    #[Test]
    public function recording_trackers_receive_observations(): void
    {
        [$required, $additional] = $this->validateWithTrackers(recording: true);

        $this->assertSame(['under-described', 'strict-additional-properties'], $required->recordedSpecsOn());
        $this->assertSame(['strict-additional-properties'], $additional->recordedSpecsOn());
        $this->assertSame(2, $additional->evaluationsOn());
    }

    /**
     * @return array{StrictRequiredTracker, StrictAdditionalPropertiesTracker}
     */
    private function validateWithTrackers(bool $recording): array
    {
        $required = new StrictRequiredTracker();
        $required->setRecording($recording);
        $additional = new StrictAdditionalPropertiesTracker();
        $additional->setRecording($recording);
        StrictAdditionalPropertiesTracker::setCurrent($additional);
        $validator = new OpenApiResponseValidator($required);

        $this->assertTrue($validator->validate(
            'under-described',
            'PUT',
            '/signed-url',
            200,
            ['expires' => 3600, 'signed_url' => 's3://...', 'url' => 'https://...'],
            'application/json',
        )->isValid());
        $this->assertTrue($validator->validate(
            'strict-additional-properties',
            'GET',
            '/users',
            200,
            ['id' => '1', 'profile' => ['name' => 'A', 'secret' => true], 'trace_id' => 't'],
            'application/json',
        )->isValid());

        return [$required, $additional];
    }
}
