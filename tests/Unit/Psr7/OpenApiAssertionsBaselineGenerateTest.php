<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit\Psr7;

use GuzzleHttp\Psr7\NoSeekStream;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\Gesso\Baseline\ViolationBaselineCollector;
use Studio\Gesso\Baseline\ViolationFingerprint;
use Studio\Gesso\Coverage\OpenApiCoverageTracker;
use Studio\Gesso\Psr7\OpenApiAssertions;
use Studio\Gesso\Spec\OpenApiSpecLoader;

use function array_map;
use function sort;

final class OpenApiAssertionsBaselineGenerateTest extends TestCase
{
    use OpenApiAssertions;
    private ViolationBaselineCollector $collector;

    protected function setUp(): void
    {
        parent::setUp();
        OpenApiSpecLoader::reset();
        OpenApiCoverageTracker::reset();
        OpenApiSpecLoader::configure(__DIR__ . '/../../fixtures/specs');
        $this->collector = new ViolationBaselineCollector();
        ViolationBaselineCollector::setCurrent($this->collector);
    }

    protected function tearDown(): void
    {
        ViolationBaselineCollector::resetCurrent();
        OpenApiSpecLoader::reset();
        OpenApiCoverageTracker::reset();
        parent::tearDown();
    }

    #[Test]
    public function a_failing_response_assertion_is_demoted_and_its_fingerprint_recorded(): void
    {
        $request = new Request('GET', 'https://example.test/body/scalar');
        $response = new Response(200, ['Content-Type' => 'application/json'], '"wrong"');

        $this->assertPsr7ResponseMatchesOpenApiSchema($request, $response);

        $entries = $this->collector->baseline()->sorted();
        $this->assertCount(1, $entries);
        $this->assertSame('psr7', $entries[0]->spec);
        $this->assertSame('GET', $entries[0]->method);
        $this->assertSame('/body/scalar', $entries[0]->path);
        $this->assertSame('response.body', $entries[0]->category);
    }

    #[Test]
    public function a_failing_exchange_records_fingerprints_from_every_failing_side(): void
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

        $this->assertPsr7ExchangeMatchesOpenApiSchema($request, $response);

        $categories = array_map(
            static fn(ViolationFingerprint $fingerprint): string => $fingerprint->category,
            $this->collector->baseline()->sorted(),
        );
        sort($categories);
        $this->assertContains('request.body', $categories);
        $this->assertContains('response.body', $categories);
    }

    #[Test]
    public function an_undecodable_response_body_records_one_parse_fingerprint_without_artifacts(): void
    {
        $request = new Request('GET', 'https://example.test/body/scalar');
        $response = new Response(200, ['Content-Type' => 'application/json'], '{invalid');

        $this->assertPsr7ResponseMatchesOpenApiSchema($request, $response);

        // Exactly the adapter's parse fingerprint — the validator ran against
        // the undecodable raw body, so its schema verdicts are artifacts of
        // the decode failure and must not enter the baseline.
        $entries = $this->collector->baseline()->sorted();
        $this->assertCount(1, $entries);
        $this->assertSame('response.body', $entries[0]->category);
        $this->assertSame('parse', $entries[0]->keyword);
        $this->assertSame('/body/scalar', $entries[0]->path);
    }

    #[Test]
    public function an_unreadable_response_stream_records_one_parse_fingerprint_without_artifacts(): void
    {
        $request = new Request('GET', 'https://example.test/body/scalar');
        $response = new Response(
            200,
            ['Content-Type' => 'application/json'],
            new NoSeekStream(Utils::streamFor('123')),
        );

        $this->assertPsr7ResponseMatchesOpenApiSchema($request, $response);

        // The validator saw the absent placeholder, so its "body is empty"
        // verdict is an artifact; only the parse fingerprint remains.
        $entries = $this->collector->baseline()->sorted();
        $this->assertCount(1, $entries);
        $this->assertSame('response.body', $entries[0]->category);
        $this->assertSame('parse', $entries[0]->keyword);
    }

    #[Test]
    public function a_genuinely_empty_body_records_a_fingerprint_distinct_from_a_decode_failure(): void
    {
        $request = new Request('GET', 'https://example.test/body/scalar');
        $response = new Response(200, ['Content-Type' => 'application/json'], '');

        $this->assertPsr7ResponseMatchesOpenApiSchema($request, $response);

        $entries = $this->collector->baseline()->sorted();
        $this->assertCount(1, $entries);
        $this->assertSame('response.body', $entries[0]->category);
        $this->assertNull(
            $entries[0]->keyword,
            'a real empty body must not share the decode-failure fingerprint',
        );
    }

    #[Test]
    public function an_exchange_request_decode_failure_records_only_the_parse_fingerprint(): void
    {
        $request = new Request(
            'POST',
            'https://example.test/notes',
            ['Content-Type' => 'application/json'],
            '{invalid',
        );
        $response = new Response(201);

        $this->assertPsr7ExchangeMatchesOpenApiSchema($request, $response);

        $entries = $this->collector->baseline()->sorted();
        $this->assertCount(1, $entries);
        $this->assertSame('request.body', $entries[0]->category);
        $this->assertSame('parse', $entries[0]->keyword);
    }

    #[Test]
    public function a_valid_exchange_records_nothing(): void
    {
        $request = new Request('GET', 'https://example.test/body/scalar');
        $response = new Response(200, ['Content-Type' => 'application/json'], '123');

        $this->assertPsr7ExchangeMatchesOpenApiSchema($request, $response);

        $this->assertSame(0, $this->collector->baseline()->count());
    }

    protected function openApiSpec(): string
    {
        return 'psr7';
    }
}
