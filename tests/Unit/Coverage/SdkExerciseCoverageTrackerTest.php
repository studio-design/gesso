<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit\Coverage;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\Gesso\Coverage\SdkExerciseCoverageTracker;

use function restore_error_handler;
use function set_error_handler;

final class SdkExerciseCoverageTrackerTest extends TestCase
{
    protected function setUp(): void
    {
        SdkExerciseCoverageTracker::resetCurrent();
    }

    protected function tearDown(): void
    {
        SdkExerciseCoverageTracker::resetCurrent();
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function provideImport_rejects_malformed_state_without_mutating_existing_observationsCases(): iterable
    {
        yield 'missing version' => [[
            'observations' => [],
        ]];
        yield 'unknown version' => [[
            'version' => 99,
            'observations' => [],
        ]];
        yield 'missing observations' => [[
            'version' => 1,
        ]];
        yield 'observations list' => [[
            'version' => 1,
            'observations' => [['not-a-spec-map']],
        ]];
        yield 'endpoint without method and path' => [[
            'version' => 1,
            'observations' => [
                'front' => [
                    'GET' => [],
                ],
            ],
        ]];
        yield 'status list' => [[
            'version' => 1,
            'observations' => [
                'front' => [
                    'GET /pets' => [
                        ['application/json' => 1],
                    ],
                ],
            ],
        ]];
        yield 'empty status key' => [[
            'version' => 1,
            'observations' => [
                'front' => [
                    'GET /pets' => [
                        '' => ['application/json' => 1],
                    ],
                ],
            ],
        ]];
        yield 'empty content type key' => [[
            'version' => 1,
            'observations' => [
                'front' => [
                    'GET /pets' => [
                        '200' => ['' => 1],
                    ],
                ],
            ],
        ]];
        yield 'zero hits' => [[
            'version' => 1,
            'observations' => [
                'front' => [
                    'GET /pets' => [
                        '200' => ['application/json' => 0],
                    ],
                ],
            ],
        ]];
        yield 'non-integer hits' => [[
            'version' => 1,
            'observations' => [
                'front' => [
                    'GET /pets' => [
                        '200' => ['application/json' => '1'],
                    ],
                ],
            ],
        ]];
    }

    #[Test]
    public function records_normalized_operations_and_accumulates_decoder_attempts(): void
    {
        $tracker = new SdkExerciseCoverageTracker();

        $tracker->recordOn('back', 'get', '/z', '2XX', 'application/problem+json');
        $tracker->recordOn('front', 'get', '/pets', '200', 'application/json');
        $tracker->recordOn('front', 'GET', '/pets', '200', 'application/json');
        $tracker->recordOn('front', 'Fetch', '/pets', 'default', 'application/json');
        $tracker->recordOn('front', 'fetch', '/pets', 'default', 'application/json');

        $this->assertSame([
            'version' => 1,
            'observations' => [
                'back' => [
                    'GET /z' => [
                        '2XX' => [
                            'application/problem+json' => 1,
                        ],
                    ],
                ],
                'front' => [
                    'Fetch /pets' => [
                        'default' => [
                            'application/json' => 1,
                        ],
                    ],
                    'GET /pets' => [
                        '200' => [
                            'application/json' => 2,
                        ],
                    ],
                    'fetch /pets' => [
                        'default' => [
                            'application/json' => 1,
                        ],
                    ],
                ],
            ],
        ], $tracker->exportStateOn());
    }

    #[Test]
    public function instances_are_isolated_and_reset_current_mints_a_fresh_tracker(): void
    {
        $first = new SdkExerciseCoverageTracker();
        $first->recordOn('front', 'GET', '/pets', '200', 'application/json');
        SdkExerciseCoverageTracker::setCurrent($first);

        $second = new SdkExerciseCoverageTracker();
        $this->assertSame([], $second->observationsForSpecOn('front'));
        $this->assertSame($first, SdkExerciseCoverageTracker::current());

        SdkExerciseCoverageTracker::resetCurrent();

        $this->assertNotSame($first, SdkExerciseCoverageTracker::current());
        $this->assertSame([], SdkExerciseCoverageTracker::current()->observationsForSpecOn('front'));
    }

    #[Test]
    public function set_current_warns_before_discarding_recorded_observations(): void
    {
        $first = new SdkExerciseCoverageTracker();
        $first->recordOn('front', 'GET', '/pets', '200', 'application/json');
        SdkExerciseCoverageTracker::setCurrent($first);

        $captured = null;
        set_error_handler(static function (int $severity, string $message) use (&$captured): bool {
            $captured = $message;

            return true;
        });

        try {
            SdkExerciseCoverageTracker::setCurrent(new SdkExerciseCoverageTracker());
        } finally {
            restore_error_handler();
        }

        $this->assertNotNull($captured);
        $this->assertStringContainsString('[OpenAPI SDK Exercise]', $captured);
        $this->assertStringContainsString('still holds recorded observations', $captured);
    }

    #[Test]
    public function import_merges_hits_without_replacing_existing_observations(): void
    {
        $tracker = new SdkExerciseCoverageTracker();
        $tracker->recordOn('front', 'GET', '/pets', '200', 'application/json');

        $tracker->importStateOn([
            'version' => 1,
            'observations' => [
                'front' => [
                    'GET /pets' => [
                        '200' => [
                            'application/json' => 2,
                            'application/problem+json' => 1,
                        ],
                    ],
                ],
            ],
        ]);

        $this->assertSame([
            'GET /pets' => [
                '200' => [
                    'application/json' => 3,
                    'application/problem+json' => 1,
                ],
            ],
        ], $tracker->observationsForSpecOn('front'));
    }

    /**
     * @param array<string, mixed> $state
     */
    #[Test]
    #[DataProvider('provideImport_rejects_malformed_state_without_mutating_existing_observationsCases')]
    public function import_rejects_malformed_state_without_mutating_existing_observations(array $state): void
    {
        $tracker = new SdkExerciseCoverageTracker();
        $tracker->recordOn('existing', 'GET', '/health', '200', 'application/json');
        $before = $tracker->exportStateOn();

        try {
            $tracker->importStateOn($state);
            $this->fail('Malformed SDK exercise coverage state must be rejected.');
        } catch (InvalidArgumentException) {
            $this->assertSame($before, $tracker->exportStateOn());
        }
    }

    #[Test]
    public function import_validation_is_atomic_across_all_rows(): void
    {
        $tracker = new SdkExerciseCoverageTracker();
        $tracker->recordOn('existing', 'GET', '/health', '200', 'application/json');
        $before = $tracker->exportStateOn();

        try {
            $tracker->importStateOn([
                'version' => 1,
                'observations' => [
                    'first-valid-row' => [
                        'GET /pets' => [
                            '200' => ['application/json' => 1],
                        ],
                    ],
                    'later-invalid-row' => [
                        'GET /users' => [
                            '200' => ['application/json' => 0],
                        ],
                    ],
                ],
            ]);
            $this->fail('A later malformed row must reject the complete import.');
        } catch (InvalidArgumentException) {
            $this->assertSame($before, $tracker->exportStateOn());
        }
    }
}
