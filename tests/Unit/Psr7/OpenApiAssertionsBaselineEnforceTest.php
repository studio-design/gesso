<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit\Psr7;

use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\Gesso\Baseline\ViolationBaseline;
use Studio\Gesso\Baseline\ViolationBaselineCollector;
use Studio\Gesso\Baseline\ViolationBaselineEnforcer;
use Studio\Gesso\Coverage\OpenApiCoverageTracker;
use Studio\Gesso\Psr7\OpenApiAssertions;
use Studio\Gesso\Spec\OpenApiSpecLoader;

final class OpenApiAssertionsBaselineEnforceTest extends TestCase
{
    use OpenApiAssertions;

    protected function setUp(): void
    {
        parent::setUp();
        OpenApiSpecLoader::reset();
        OpenApiCoverageTracker::reset();
        OpenApiSpecLoader::configure(__DIR__ . '/../../fixtures/specs');
    }

    protected function tearDown(): void
    {
        ViolationBaselineCollector::resetCurrent();
        ViolationBaselineEnforcer::resetCurrent();
        OpenApiSpecLoader::reset();
        OpenApiCoverageTracker::reset();
        parent::tearDown();
    }

    #[Test]
    public function a_fully_baselined_failing_response_is_suppressed_and_hit(): void
    {
        $request = new Request('GET', 'https://example.test/body/scalar');
        $response = new Response(200, ['Content-Type' => 'application/json'], '"wrong"');

        $enforcer = $this->generateBaselineFrom(
            fn() => $this->assertPsr7ResponseMatchesOpenApiSchema($request, $response),
        );

        $this->assertPsr7ResponseMatchesOpenApiSchema($request, $response);

        $this->assertSame($enforcer->baseline()->count(), $enforcer->hitCount());
        $this->assertSame([], $enforcer->staleEntries());
    }

    #[Test]
    public function a_new_violation_fails_against_an_empty_baseline(): void
    {
        ViolationBaselineEnforcer::setCurrent(new ViolationBaselineEnforcer(new ViolationBaseline()));

        $request = new Request('GET', 'https://example.test/body/scalar');
        $response = new Response(200, ['Content-Type' => 'application/json'], '"wrong"');

        try {
            $this->assertPsr7ResponseMatchesOpenApiSchema($request, $response);
            $this->fail('Expected the assertion to fail on the unbaselined violation.');
        } catch (AssertionFailedError $e) {
            $this->assertStringContainsString('OpenAPI PSR-7 response validation failed', $e->getMessage());
        }
    }

    #[Test]
    public function an_exchange_with_every_failing_side_baselined_is_suppressed(): void
    {
        $request = new Request(
            'POST',
            'https://example.test/widgets/42',
            ['Content-Type' => 'application/json'],
            '{"message":123}',
        );
        $response = new Response(
            201,
            ['Content-Type' => 'application/json', 'X-Trace' => 'trace-1'],
            '{"id":"not-an-integer"}',
        );

        $enforcer = $this->generateBaselineFrom(
            fn() => $this->assertPsr7ExchangeMatchesOpenApiSchema($request, $response),
        );

        $this->assertPsr7ExchangeMatchesOpenApiSchema($request, $response);

        $this->assertSame($enforcer->baseline()->count(), $enforcer->hitCount());
    }

    #[Test]
    public function an_exchange_with_one_unbaselined_side_fails_but_marks_hits(): void
    {
        // Baseline only the response-side debt: generate against a fully
        // valid request paired with the failing response.
        $validRequest = new Request(
            'POST',
            'https://example.test/widgets/42?q=search',
            [
                'Content-Type' => 'application/json',
                'X-Token' => 'token-1',
                'Cookie' => 'session=abc',
            ],
            '{"message":"ok"}',
        );
        $failingResponse = new Response(
            201,
            ['Content-Type' => 'application/json', 'X-Trace' => 'trace-1'],
            '{"id":"not-an-integer"}',
        );

        $enforcer = $this->generateBaselineFrom(
            fn() => $this->assertPsr7ExchangeMatchesOpenApiSchema($validRequest, $failingResponse),
        );
        $this->assertGreaterThan(0, $enforcer->baseline()->count());

        // Same failing response, but now the request side fails too — the
        // exchange must fail as a whole while the baselined response-side
        // entries still count as hit (they occurred).
        $failingRequest = new Request(
            'POST',
            'https://example.test/widgets/42',
            ['Content-Type' => 'application/json'],
            '{"message":123}',
        );

        try {
            $this->assertPsr7ExchangeMatchesOpenApiSchema($failingRequest, $failingResponse);
            $this->fail('Expected the exchange to fail on the unbaselined request side.');
        } catch (AssertionFailedError) {
            $this->assertSame($enforcer->baseline()->count(), $enforcer->hitCount());
        }
    }

    protected function openApiSpec(): string
    {
        return 'psr7';
    }

    /**
     * Run one generation pass over `$assert` and install the collected
     * fingerprints as the enforcement baseline — the exact generate → commit
     * → enforce round-trip a user performs.
     *
     * @param callable(): void $assert
     */
    private function generateBaselineFrom(callable $assert): ViolationBaselineEnforcer
    {
        $collector = new ViolationBaselineCollector();
        ViolationBaselineCollector::setCurrent($collector);
        $assert();
        ViolationBaselineCollector::resetCurrent();

        $enforcer = new ViolationBaselineEnforcer($collector->baseline());
        ViolationBaselineEnforcer::setCurrent($enforcer);

        return $enforcer;
    }
}
