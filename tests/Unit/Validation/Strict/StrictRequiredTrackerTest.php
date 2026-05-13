<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Tests\Unit\Validation\Strict;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\OpenApiContractTesting\Validation\Strict\StrictRequiredTracker;

class StrictRequiredTrackerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        StrictRequiredTracker::reset();
    }

    protected function tearDown(): void
    {
        StrictRequiredTracker::reset();
        parent::tearDown();
    }

    #[Test]
    public function single_observation_keeps_full_key_set(): void
    {
        StrictRequiredTracker::record('front', 'GET', '/x', '200', 'application/json', ['a', 'b', 'c']);

        $observations = StrictRequiredTracker::getObservations('front');
        $this->assertSame(
            [
                'GET /x' => [
                    '200:application/json' => ['hits' => 1, 'alwaysPresent' => ['a', 'b', 'c']],
                ],
            ],
            $observations,
        );
    }

    #[Test]
    public function multiple_observations_intersect_keys(): void
    {
        StrictRequiredTracker::record('front', 'GET', '/x', '200', 'application/json', ['a', 'b', 'c']);
        StrictRequiredTracker::record('front', 'GET', '/x', '200', 'application/json', ['a', 'b']);
        StrictRequiredTracker::record('front', 'GET', '/x', '200', 'application/json', ['a', 'b', 'd']);

        $observations = StrictRequiredTracker::getObservations('front');
        $this->assertSame(
            [
                'GET /x' => [
                    '200:application/json' => ['hits' => 3, 'alwaysPresent' => ['a', 'b']],
                ],
            ],
            $observations,
        );
    }

    #[Test]
    public function empty_body_observation_drops_intersection_to_empty(): void
    {
        StrictRequiredTracker::record('front', 'GET', '/x', '200', 'application/json', ['a', 'b']);
        StrictRequiredTracker::record('front', 'GET', '/x', '200', 'application/json', []);

        $observations = StrictRequiredTracker::getObservations('front');
        $this->assertSame(
            [
                'GET /x' => [
                    '200:application/json' => ['hits' => 2, 'alwaysPresent' => []],
                ],
            ],
            $observations,
        );
    }

    #[Test]
    public function different_status_keys_are_independent(): void
    {
        StrictRequiredTracker::record('front', 'GET', '/x', '200', 'application/json', ['a']);
        StrictRequiredTracker::record('front', 'GET', '/x', '404', 'application/json', ['error']);

        $observations = StrictRequiredTracker::getObservations('front');
        $this->assertSame(
            [
                'GET /x' => [
                    '200:application/json' => ['hits' => 1, 'alwaysPresent' => ['a']],
                    '404:application/json' => ['hits' => 1, 'alwaysPresent' => ['error']],
                ],
            ],
            $observations,
        );
    }

    #[Test]
    public function different_content_types_are_independent(): void
    {
        StrictRequiredTracker::record('front', 'GET', '/x', '200', 'application/json', ['a']);
        StrictRequiredTracker::record('front', 'GET', '/x', '200', 'application/problem+json', ['detail']);

        $observations = StrictRequiredTracker::getObservations('front');
        $this->assertSame(
            [
                'GET /x' => [
                    '200:application/json' => ['hits' => 1, 'alwaysPresent' => ['a']],
                    '200:application/problem+json' => ['hits' => 1, 'alwaysPresent' => ['detail']],
                ],
            ],
            $observations,
        );
    }

    #[Test]
    public function different_specs_are_independent(): void
    {
        StrictRequiredTracker::record('front', 'GET', '/x', '200', 'application/json', ['a']);
        StrictRequiredTracker::record('admin', 'GET', '/x', '200', 'application/json', ['b']);

        $this->assertSame(
            ['GET /x' => ['200:application/json' => ['hits' => 1, 'alwaysPresent' => ['a']]]],
            StrictRequiredTracker::getObservations('front'),
        );
        $this->assertSame(
            ['GET /x' => ['200:application/json' => ['hits' => 1, 'alwaysPresent' => ['b']]]],
            StrictRequiredTracker::getObservations('admin'),
        );
    }

    #[Test]
    public function record_normalises_method_to_uppercase(): void
    {
        StrictRequiredTracker::record('front', 'get', '/x', '200', 'application/json', ['a']);

        $observations = StrictRequiredTracker::getObservations('front');
        $this->assertArrayHasKey('GET /x', $observations);
    }

    #[Test]
    public function reset_clears_all_specs(): void
    {
        StrictRequiredTracker::record('front', 'GET', '/x', '200', 'application/json', ['a']);
        StrictRequiredTracker::record('admin', 'GET', '/y', '200', 'application/json', ['b']);

        StrictRequiredTracker::reset();

        $this->assertSame([], StrictRequiredTracker::getObservations('front'));
        $this->assertSame([], StrictRequiredTracker::getObservations('admin'));
    }

    #[Test]
    public function export_and_import_round_trip(): void
    {
        StrictRequiredTracker::record('front', 'GET', '/x', '200', 'application/json', ['a', 'b']);
        StrictRequiredTracker::record('front', 'GET', '/x', '200', 'application/json', ['a', 'b', 'c']);
        StrictRequiredTracker::record('front', 'POST', '/y', '201', 'application/json', ['id']);

        $exported = StrictRequiredTracker::exportState();

        StrictRequiredTracker::reset();
        $this->assertSame([], StrictRequiredTracker::getObservations('front'));

        StrictRequiredTracker::importState($exported);

        $this->assertSame(
            [
                'GET /x' => ['200:application/json' => ['hits' => 2, 'alwaysPresent' => ['a', 'b']]],
                'POST /y' => ['201:application/json' => ['hits' => 1, 'alwaysPresent' => ['id']]],
            ],
            StrictRequiredTracker::getObservations('front'),
        );
    }

    #[Test]
    public function import_unions_with_existing_via_intersection(): void
    {
        StrictRequiredTracker::record('front', 'GET', '/x', '200', 'application/json', ['a', 'b', 'c']);

        StrictRequiredTracker::importState([
            'version' => 1,
            'observations' => [
                'front' => [
                    'GET /x' => [
                        '200:application/json' => ['hits' => 2, 'alwaysPresent' => ['a', 'b']],
                    ],
                ],
            ],
        ]);

        $this->assertSame(
            ['GET /x' => ['200:application/json' => ['hits' => 3, 'alwaysPresent' => ['a', 'b']]]],
            StrictRequiredTracker::getObservations('front'),
        );
    }

    #[Test]
    public function import_rejects_unknown_version(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported strict_required state version');

        StrictRequiredTracker::importState([
            'version' => 99,
            'observations' => [],
        ]);
    }
}
