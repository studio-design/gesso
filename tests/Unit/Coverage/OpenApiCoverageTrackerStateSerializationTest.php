<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Tests\Unit\Coverage;

use const JSON_THROW_ON_ERROR;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\OpenApiContractTesting\Coverage\OpenApiCoverageTracker;
use Studio\OpenApiContractTesting\Spec\OpenApiSpecLoader;

use function json_decode;
use function json_encode;

/**
 * Pins the JSON-safe state shape produced by {@see OpenApiCoverageTracker::exportState()}
 * and the union-merge semantics of {@see OpenApiCoverageTracker::importState()}.
 *
 * The merge CLI loads N worker sidecars by calling importState() N times, so
 * the merge rules MUST mirror the live recording rules: validated wins over
 * skipped, hits accumulate, and the latest non-null skipReason wins.
 */
class OpenApiCoverageTrackerStateSerializationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        OpenApiCoverageTracker::reset();
        OpenApiSpecLoader::reset();
        OpenApiSpecLoader::configure(__DIR__ . '/../../fixtures/specs');
    }

    protected function tearDown(): void
    {
        OpenApiCoverageTracker::reset();
        OpenApiSpecLoader::reset();
        parent::tearDown();
    }

    #[Test]
    public function export_state_returns_empty_payload_when_nothing_recorded(): void
    {
        $state = OpenApiCoverageTracker::exportState();

        $this->assertSame(['version' => 1, 'specs' => []], $state);
    }

    #[Test]
    public function export_state_serializes_request_only_endpoint(): void
    {
        OpenApiCoverageTracker::recordRequest('petstore-3.0', 'GET', '/v1/pets');

        $state = OpenApiCoverageTracker::exportState();

        $this->assertSame([
            'version' => 1,
            'specs' => [
                'petstore-3.0' => [
                    'GET /v1/pets' => [
                        'requestReached' => true,
                        'requestSkipReason' => null,
                        'responses' => [],
                    ],
                ],
            ],
        ], $state);
    }

    #[Test]
    public function export_state_serializes_validated_response(): void
    {
        OpenApiCoverageTracker::recordResponse(
            'petstore-3.0',
            'GET',
            '/v1/pets',
            '200',
            'application/json',
            schemaValidated: true,
        );

        $state = OpenApiCoverageTracker::exportState();

        $this->assertSame([
            'version' => 1,
            'specs' => [
                'petstore-3.0' => [
                    'GET /v1/pets' => [
                        'requestReached' => false,
                        'requestSkipReason' => null,
                        'responses' => [
                            '200:application/json' => [
                                'state' => 'validated',
                                'hits' => 1,
                                'skipReason' => null,
                            ],
                        ],
                    ],
                ],
            ],
        ], $state);
    }

    #[Test]
    public function export_state_serializes_skipped_response_with_reason(): void
    {
        OpenApiCoverageTracker::recordResponse(
            'petstore-3.0',
            'GET',
            '/v1/pets',
            '503',
            null,
            schemaValidated: false,
            skipReason: 'status 503 matched skip pattern 5\\d\\d',
        );

        $state = OpenApiCoverageTracker::exportState();

        $this->assertSame([
            'version' => 1,
            'specs' => [
                'petstore-3.0' => [
                    'GET /v1/pets' => [
                        'requestReached' => false,
                        'requestSkipReason' => null,
                        'responses' => [
                            '503:*' => [
                                'state' => 'skipped',
                                'hits' => 1,
                                'skipReason' => 'status 503 matched skip pattern 5\\d\\d',
                            ],
                        ],
                    ],
                ],
            ],
        ], $state);
    }

    #[Test]
    public function export_state_round_trips_through_json(): void
    {
        OpenApiCoverageTracker::recordRequest('petstore-3.0', 'GET', '/v1/pets');
        OpenApiCoverageTracker::recordResponse(
            'petstore-3.0',
            'GET',
            '/v1/pets',
            '200',
            'application/json',
            schemaValidated: true,
        );
        OpenApiCoverageTracker::recordResponse(
            'petstore-3.0',
            'GET',
            '/v1/pets/{petId}',
            '503',
            null,
            schemaValidated: false,
            skipReason: 'matched 5\\d\\d',
        );

        $original = OpenApiCoverageTracker::exportState();
        $json = json_encode($original, JSON_THROW_ON_ERROR);
        $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        OpenApiCoverageTracker::reset();
        OpenApiCoverageTracker::importState($decoded);

        $this->assertSame($original, OpenApiCoverageTracker::exportState());
    }

    #[Test]
    public function import_state_is_additive_for_disjoint_specs(): void
    {
        // Worker A's state.
        OpenApiCoverageTracker::recordResponse(
            'petstore-3.0',
            'GET',
            '/v1/pets',
            '200',
            'application/json',
            schemaValidated: true,
        );
        $workerA = OpenApiCoverageTracker::exportState();

        // Worker B's state, captured from a fresh tracker.
        OpenApiCoverageTracker::reset();
        OpenApiCoverageTracker::recordResponse(
            'range-keys',
            'GET',
            '/widgets-default',
            '200',
            'application/json',
            schemaValidated: true,
        );
        $workerB = OpenApiCoverageTracker::exportState();

        // Merge both into one tracker.
        OpenApiCoverageTracker::reset();
        OpenApiCoverageTracker::importState($workerA);
        OpenApiCoverageTracker::importState($workerB);

        $merged = OpenApiCoverageTracker::exportState();
        $this->assertArrayHasKey('petstore-3.0', $merged['specs']);
        $this->assertArrayHasKey('range-keys', $merged['specs']);
        $this->assertSame(
            1,
            $merged['specs']['petstore-3.0']['GET /v1/pets']['responses']['200:application/json']['hits'],
        );
        $this->assertSame(
            1,
            $merged['specs']['range-keys']['GET /widgets-default']['responses']['200:application/json']['hits'],
        );
    }

    #[Test]
    public function import_state_accumulates_hits_for_same_pair(): void
    {
        // Two workers both validated GET /v1/pets 200:application/json once.
        $worker = [
            'version' => 1,
            'specs' => [
                'petstore-3.0' => [
                    'GET /v1/pets' => [
                        'requestReached' => false,
                        'responses' => [
                            '200:application/json' => [
                                'state' => 'validated',
                                'hits' => 1,
                                'skipReason' => null,
                            ],
                        ],
                    ],
                ],
            ],
        ];

        OpenApiCoverageTracker::importState($worker);
        OpenApiCoverageTracker::importState($worker);

        $merged = OpenApiCoverageTracker::exportState();
        $this->assertSame(
            2,
            $merged['specs']['petstore-3.0']['GET /v1/pets']['responses']['200:application/json']['hits'],
        );
    }

    #[Test]
    public function import_state_promotes_skipped_to_validated(): void
    {
        $skippedFirst = [
            'version' => 1,
            'specs' => [
                'petstore-3.0' => [
                    'GET /v1/pets' => [
                        'requestReached' => false,
                        'responses' => [
                            '200:application/json' => [
                                'state' => 'skipped',
                                'hits' => 2,
                                'skipReason' => 'matched 2xx-skip',
                            ],
                        ],
                    ],
                ],
            ],
        ];
        $validatedSecond = [
            'version' => 1,
            'specs' => [
                'petstore-3.0' => [
                    'GET /v1/pets' => [
                        'requestReached' => false,
                        'responses' => [
                            '200:application/json' => [
                                'state' => 'validated',
                                'hits' => 1,
                                'skipReason' => null,
                            ],
                        ],
                    ],
                ],
            ],
        ];

        OpenApiCoverageTracker::importState($skippedFirst);
        OpenApiCoverageTracker::importState($validatedSecond);

        $merged = OpenApiCoverageTracker::exportState();
        $row = $merged['specs']['petstore-3.0']['GET /v1/pets']['responses']['200:application/json'];
        $this->assertSame('validated', $row['state']);
        $this->assertNull($row['skipReason']);
        // Bulk merge sums hits across both sources — matches sequential
        // recordResponse() calls where hits++ runs unconditionally and
        // state promotion does not roll the counter back.
        $this->assertSame(3, $row['hits']);
    }

    #[Test]
    public function import_state_keeps_validated_when_skipped_arrives_later(): void
    {
        $validatedFirst = [
            'version' => 1,
            'specs' => [
                'petstore-3.0' => [
                    'GET /v1/pets' => [
                        'requestReached' => false,
                        'responses' => [
                            '200:application/json' => [
                                'state' => 'validated',
                                'hits' => 3,
                                'skipReason' => null,
                            ],
                        ],
                    ],
                ],
            ],
        ];
        $skippedSecond = [
            'version' => 1,
            'specs' => [
                'petstore-3.0' => [
                    'GET /v1/pets' => [
                        'requestReached' => false,
                        'responses' => [
                            '200:application/json' => [
                                'state' => 'skipped',
                                'hits' => 1,
                                'skipReason' => 'late skip',
                            ],
                        ],
                    ],
                ],
            ],
        ];

        OpenApiCoverageTracker::importState($validatedFirst);
        OpenApiCoverageTracker::importState($skippedSecond);

        $merged = OpenApiCoverageTracker::exportState();
        $row = $merged['specs']['petstore-3.0']['GET /v1/pets']['responses']['200:application/json'];
        $this->assertSame('validated', $row['state']);
        $this->assertNull($row['skipReason']);
        $this->assertSame(4, $row['hits']);
    }

    #[Test]
    public function import_state_keeps_latest_skip_reason_when_both_sides_skipped(): void
    {
        $first = [
            'version' => 1,
            'specs' => [
                'petstore-3.0' => [
                    'GET /v1/pets' => [
                        'requestReached' => false,
                        'responses' => [
                            '503:*' => [
                                'state' => 'skipped',
                                'hits' => 2,
                                'skipReason' => 'old reason',
                            ],
                        ],
                    ],
                ],
            ],
        ];
        $second = [
            'version' => 1,
            'specs' => [
                'petstore-3.0' => [
                    'GET /v1/pets' => [
                        'requestReached' => false,
                        'responses' => [
                            '503:*' => [
                                'state' => 'skipped',
                                'hits' => 1,
                                'skipReason' => 'new reason',
                            ],
                        ],
                    ],
                ],
            ],
        ];

        OpenApiCoverageTracker::importState($first);
        OpenApiCoverageTracker::importState($second);

        $merged = OpenApiCoverageTracker::exportState();
        $row = $merged['specs']['petstore-3.0']['GET /v1/pets']['responses']['503:*'];
        $this->assertSame('skipped', $row['state']);
        $this->assertSame('new reason', $row['skipReason']);
        $this->assertSame(3, $row['hits']);
    }

    #[Test]
    public function import_state_unions_request_reached_flag(): void
    {
        $reached = [
            'version' => 1,
            'specs' => [
                'petstore-3.0' => [
                    'GET /v1/pets' => [
                        'requestReached' => true,
                        'responses' => [],
                    ],
                ],
            ],
        ];
        $notReached = [
            'version' => 1,
            'specs' => [
                'petstore-3.0' => [
                    'GET /v1/pets' => [
                        'requestReached' => false,
                        'responses' => [
                            '200:application/json' => [
                                'state' => 'validated',
                                'hits' => 1,
                                'skipReason' => null,
                            ],
                        ],
                    ],
                ],
            ],
        ];

        OpenApiCoverageTracker::importState($reached);
        OpenApiCoverageTracker::importState($notReached);

        $merged = OpenApiCoverageTracker::exportState();
        $endpoint = $merged['specs']['petstore-3.0']['GET /v1/pets'];
        $this->assertTrue($endpoint['requestReached']);
        $this->assertArrayHasKey('200:application/json', $endpoint['responses']);
    }

    #[Test]
    public function import_state_rejects_unknown_version(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('unsupported coverage state version');

        OpenApiCoverageTracker::importState(['version' => 99, 'specs' => []]);
    }

    #[Test]
    public function import_state_rejects_missing_version(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('missing "version"');

        OpenApiCoverageTracker::importState(['specs' => []]);
    }

    #[Test]
    public function import_state_rejects_invalid_response_state(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('invalid response state');

        OpenApiCoverageTracker::importState([
            'version' => 1,
            'specs' => [
                'petstore-3.0' => [
                    'GET /v1/pets' => [
                        'requestReached' => false,
                        'responses' => [
                            '200:application/json' => [
                                'state' => 'bogus',
                                'hits' => 1,
                                'skipReason' => null,
                            ],
                        ],
                    ],
                ],
            ],
        ]);
    }

    #[Test]
    public function import_state_rejects_non_string_spec_name(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('invalid spec entry');

        OpenApiCoverageTracker::importState([
            'version' => 1,
            'specs' => [
                42 => ['GET /v1/pets' => ['requestReached' => true, 'responses' => []]],
            ],
        ]);
    }

    #[Test]
    public function import_state_rejects_non_array_endpoint_entry(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('invalid endpoint entry');

        OpenApiCoverageTracker::importState([
            'version' => 1,
            'specs' => [
                'petstore-3.0' => ['GET /v1/pets' => 'not an array'],
            ],
        ]);
    }

    #[Test]
    public function import_state_rejects_non_array_response_row(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('invalid response entry');

        OpenApiCoverageTracker::importState([
            'version' => 1,
            'specs' => [
                'petstore-3.0' => [
                    'GET /v1/pets' => [
                        'requestReached' => false,
                        'responses' => ['200:application/json' => 'not an array'],
                    ],
                ],
            ],
        ]);
    }

    #[Test]
    public function import_state_rejects_non_string_response_state(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('invalid response state');

        OpenApiCoverageTracker::importState([
            'version' => 1,
            'specs' => [
                'petstore-3.0' => [
                    'GET /v1/pets' => [
                        'requestReached' => false,
                        'responses' => ['200:application/json' => [
                            'state' => 42,
                            'hits' => 1,
                            'skipReason' => null,
                        ]],
                    ],
                ],
            ],
        ]);
    }

    #[Test]
    public function import_state_does_not_partially_apply_when_payload_is_invalid(): void
    {
        // Pre-PR, importer mutated state per-endpoint and threw partway,
        // leaving the tracker inconsistent. Two-pass validate-then-apply
        // must roll back cleanly: nothing is mutated when validation
        // would eventually fail.
        OpenApiCoverageTracker::recordResponse(
            'petstore-3.0',
            'GET',
            '/v1/pets',
            '200',
            'application/json',
            schemaValidated: true,
        );
        $beforeSnapshot = OpenApiCoverageTracker::exportState();

        try {
            OpenApiCoverageTracker::importState([
                'version' => 1,
                'specs' => [
                    'petstore-3.0' => [
                        'POST /v1/pets' => [
                            'requestReached' => false,
                            'responses' => ['201:application/json' => [
                                'state' => 'validated',
                                'hits' => 1,
                                'skipReason' => null,
                            ]],
                        ],
                        'GET /v1/widgets' => [
                            'requestReached' => false,
                            'responses' => ['200:application/json' => [
                                'state' => 'bogus',
                                'hits' => 1,
                                'skipReason' => null,
                            ]],
                        ],
                    ],
                ],
            ]);
            $this->fail('expected InvalidArgumentException');
        } catch (InvalidArgumentException) {
            // Expected — the second endpoint's "bogus" state must abort.
        }

        $this->assertSame($beforeSnapshot, OpenApiCoverageTracker::exportState());
    }

    #[Test]
    public function export_state_round_trip_preserves_request_skip_reason(): void
    {
        // Issue #179: a recordRequest call with a skip reason (downgraded
        // failure on documented 4xx) must serialize round-trip cleanly so
        // paratest worker sidecars carry the field across to the merge CLI.
        OpenApiCoverageTracker::recordRequest(
            'petstore-3.0',
            'POST',
            '/v1/pets',
            'request validation skipped: response 422 is documented (spec key 422)',
        );

        $original = OpenApiCoverageTracker::exportState();
        $json = json_encode($original, JSON_THROW_ON_ERROR);
        $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        OpenApiCoverageTracker::reset();
        OpenApiCoverageTracker::importState($decoded);

        $this->assertSame($original, OpenApiCoverageTracker::exportState());
        $endpoint = $original['specs']['petstore-3.0']['POST /v1/pets'];
        $this->assertTrue($endpoint['requestReached']);
        $this->assertSame(
            'request validation skipped: response 422 is documented (spec key 422)',
            $endpoint['requestSkipReason'],
        );
    }

    #[Test]
    public function import_state_tolerates_payloads_without_request_skip_reason_field(): void
    {
        // Strictly additive: paratest sidecars written by an older library
        // version (no `requestSkipReason` key) must still import cleanly. The
        // missing field defaults to null. This keeps the wire format
        // backward-compatible without a STATE_FORMAT_VERSION bump.
        OpenApiCoverageTracker::importState([
            'version' => 1,
            'specs' => [
                'petstore-3.0' => [
                    'POST /v1/pets' => [
                        'requestReached' => true,
                        'responses' => [],
                        // no 'requestSkipReason' key
                    ],
                ],
            ],
        ]);

        $state = OpenApiCoverageTracker::exportState();
        $endpoint = $state['specs']['petstore-3.0']['POST /v1/pets'];
        $this->assertTrue($endpoint['requestReached']);
        $this->assertNull($endpoint['requestSkipReason']);
    }

    #[Test]
    public function import_state_rejects_non_string_request_skip_reason(): void
    {
        // C2 hardening: a corrupt sidecar with a non-null, non-string
        // requestSkipReason (int, array, bool — i.e. real corruption from
        // a buggy serializer or hand-edit) must throw rather than silently
        // coercing to null. Mirrors the response-side `state` strictness.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('invalid requestSkipReason in coverage state payload');

        OpenApiCoverageTracker::importState([
            'version' => 1,
            'specs' => [
                'petstore-3.0' => [
                    'POST /v1/pets' => [
                        'requestReached' => true,
                        'requestSkipReason' => 42,
                        'responses' => [],
                    ],
                ],
            ],
        ]);
    }

    #[Test]
    public function reconcile_request_agrees_between_record_and_import_paths(): void
    {
        // Companion to reconcile_response_agrees_between_record_and_import_paths:
        // the bulk-merge importState branch must produce the same final
        // requestSkipReason as the sequential recordRequest branch when fed
        // equivalent observations. Pinned here so a future tweak to one
        // path and not the other is caught immediately.
        OpenApiCoverageTracker::reset();
        OpenApiCoverageTracker::recordRequest('petstore-3.0', 'GET', '/v1/pets', 'reason-1');
        OpenApiCoverageTracker::recordRequest('petstore-3.0', 'GET', '/v1/pets', 'reason-2');
        OpenApiCoverageTracker::recordRequest('petstore-3.0', 'GET', '/v1/pets');
        $sequential = OpenApiCoverageTracker::exportState();

        OpenApiCoverageTracker::reset();
        OpenApiCoverageTracker::importState([
            'version' => 1,
            'specs' => ['petstore-3.0' => ['GET /v1/pets' => [
                'requestReached' => true,
                'requestSkipReason' => 'reason-2',
                'responses' => [],
            ]]],
        ]);
        OpenApiCoverageTracker::importState([
            'version' => 1,
            'specs' => ['petstore-3.0' => ['GET /v1/pets' => [
                'requestReached' => true,
                'requestSkipReason' => null,
                'responses' => [],
            ]]],
        ]);
        $bulk = OpenApiCoverageTracker::exportState();

        $this->assertSame($sequential, $bulk);
    }

    #[Test]
    public function import_state_does_not_demote_validated_request_to_skipped(): void
    {
        // Mirror of import_state_keeps_validated_when_skipped_arrives_later
        // for the request side. Once a worker recorded a clean request
        // validation, a later worker's downgrade for the same endpoint
        // must not flip it back to skipped.
        OpenApiCoverageTracker::importState([
            'version' => 1,
            'specs' => ['petstore-3.0' => ['GET /v1/pets' => [
                'requestReached' => true,
                'requestSkipReason' => null,
                'responses' => [],
            ]]],
        ]);
        OpenApiCoverageTracker::importState([
            'version' => 1,
            'specs' => ['petstore-3.0' => ['GET /v1/pets' => [
                'requestReached' => true,
                'requestSkipReason' => 'late downgrade',
                'responses' => [],
            ]]],
        ]);

        $merged = OpenApiCoverageTracker::exportState();
        $this->assertNull($merged['specs']['petstore-3.0']['GET /v1/pets']['requestSkipReason']);
    }

    #[Test]
    public function import_state_after_record_response_preserves_request_skip_reason(): void
    {
        // C1 regression in the bulk-merge path: a worker A that wrote a
        // response-only sidecar must not erase the skipReason carried by
        // worker B's request-side payload when they merge.
        OpenApiCoverageTracker::importState([
            'version' => 1,
            'specs' => ['petstore-3.0' => ['GET /v1/pets' => [
                'requestReached' => false,
                'requestSkipReason' => null,
                'responses' => [
                    '200:application/json' => ['state' => 'validated', 'hits' => 1, 'skipReason' => null],
                ],
            ]]],
        ]);
        OpenApiCoverageTracker::importState([
            'version' => 1,
            'specs' => ['petstore-3.0' => ['GET /v1/pets' => [
                'requestReached' => true,
                'requestSkipReason' => 'downgraded after response',
                'responses' => [],
            ]]],
        ]);

        $merged = OpenApiCoverageTracker::exportState();
        $endpoint = $merged['specs']['petstore-3.0']['GET /v1/pets'];
        $this->assertTrue($endpoint['requestReached']);
        $this->assertSame('downgraded after response', $endpoint['requestSkipReason']);
    }

    #[Test]
    public function reconcile_response_agrees_between_record_and_import_paths(): void
    {
        // Sequential single-record path: 2 skip + 1 validated.
        OpenApiCoverageTracker::reset();
        OpenApiCoverageTracker::recordResponse('petstore-3.0', 'GET', '/v1/pets', '200', 'application/json', schemaValidated: false, skipReason: 'reason-1');
        OpenApiCoverageTracker::recordResponse('petstore-3.0', 'GET', '/v1/pets', '200', 'application/json', schemaValidated: false, skipReason: 'reason-2');
        OpenApiCoverageTracker::recordResponse('petstore-3.0', 'GET', '/v1/pets', '200', 'application/json', schemaValidated: true);
        $sequential = OpenApiCoverageTracker::exportState();

        // Bulk-merge path: equivalent observations split across two payloads.
        // First payload aggregates two skipped recordings into a single
        // entry of hits=2 with the latest skipReason.
        OpenApiCoverageTracker::reset();
        OpenApiCoverageTracker::importState([
            'version' => 1,
            'specs' => ['petstore-3.0' => ['GET /v1/pets' => [
                'requestReached' => false,
                'responses' => ['200:application/json' => ['state' => 'skipped', 'hits' => 2, 'skipReason' => 'reason-2']],
            ]]],
        ]);
        OpenApiCoverageTracker::importState([
            'version' => 1,
            'specs' => ['petstore-3.0' => ['GET /v1/pets' => [
                'requestReached' => false,
                'responses' => ['200:application/json' => ['state' => 'validated', 'hits' => 1, 'skipReason' => null]],
            ]]],
        ]);
        $bulk = OpenApiCoverageTracker::exportState();

        // The two paths must agree on the final reconciled state — pinned
        // here so a future tweak to one branch and not the other is caught.
        $this->assertSame($sequential, $bulk);
    }
}
