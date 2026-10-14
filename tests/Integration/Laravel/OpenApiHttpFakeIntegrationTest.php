<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Integration\Laravel;

use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\Test;
use Studio\Gesso\Attribute\OpenApiSpec;
use Studio\Gesso\Coverage\OpenApiCoverageTracker;
use Studio\Gesso\Laravel\OpenApiHttpFake;
use Studio\Gesso\Psr7\OpenApiAssertions;
use Studio\Gesso\Spec\OpenApiSpecLoader;

use function dirname;

/**
 * Consumer-side contract testing under a real Laravel HTTP client: the
 * fake answers `Http::get()` from the external spec, and a request the spec
 * rejects fails the test from inside the client call.
 */
#[OpenApiSpec('consumer-fake')]
class OpenApiHttpFakeIntegrationTest extends TestCase
{
    use OpenApiAssertions;

    protected function setUp(): void
    {
        parent::setUp();
        OpenApiSpecLoader::reset();
        OpenApiSpecLoader::configure(dirname(__DIR__, 2) . '/fixtures/specs');
        OpenApiCoverageTracker::reset();
    }

    protected function tearDown(): void
    {
        OpenApiSpecLoader::reset();
        OpenApiCoverageTracker::reset();
        parent::tearDown();
    }

    #[Test]
    public function one_call_fakes_the_external_api(): void
    {
        Http::fake(OpenApiHttpFake::respond('consumer-fake'));

        $response = Http::get('https://pets.example/v1/pets/7');

        $this->assertSame(200, $response->status());
        $this->assertSame(7, $response->json('id'));
        $this->assertSame('req-1', $response->header('X-Request-Id'));
    }

    #[Test]
    public function status_override_selects_the_declared_error_response(): void
    {
        Http::fake(OpenApiHttpFake::respond('consumer-fake', ['GET /v1/pets/{id}' => 404]));

        $response = Http::get('https://pets.example/v1/pets/7');

        $this->assertSame(404, $response->status());
        $this->assertSame('application/problem+json', $response->header('Content-Type'));
        $this->assertSame('Pet not found', $response->json('title'));
    }

    #[Test]
    public function invalid_outgoing_request_fails_the_test(): void
    {
        Http::fake(OpenApiHttpFake::respond('consumer-fake'));

        try {
            Http::post('https://pets.example/v1/pets', ['nme' => 'x']);
            $this->fail('The fake should reject a request the spec does not accept.');
        } catch (AssertionFailedError $e) {
            $this->assertStringContainsString('POST /v1/pets', $e->getMessage());
            $this->assertStringContainsString('Reproduce: curl', $e->getMessage());
        }
    }

    #[Test]
    public function url_scoped_fake_composes_with_other_fakes(): void
    {
        Http::fake([
            'pets.example/*' => OpenApiHttpFake::respond('consumer-fake'),
            'other.example/*' => Http::response('x'),
        ]);

        $this->assertSame(7, Http::get('https://pets.example/v1/pets/7')->json('id'));
        $this->assertSame('x', Http::get('https://other.example/anything')->body());
    }

    #[Test]
    public function recorded_pairs_can_be_asserted_with_the_psr7_trait(): void
    {
        Http::fake(OpenApiHttpFake::respond('consumer-fake'));
        Http::get('https://pets.example/v1/pets/7');

        $this->assertRecordedExchangesMatchSpec();
    }

    #[Test]
    public function stale_hand_written_fake_fails_the_psr7_assertion(): void
    {
        Http::fake(['pets.example/*' => Http::response(['id' => 'seven'], 200)]);
        Http::get('https://pets.example/v1/pets/7');

        $this->expectException(AssertionFailedError::class);
        $this->assertRecordedExchangesMatchSpec();
    }

    #[Test]
    public function request_coverage_is_recorded_for_the_external_spec(): void
    {
        Http::fake(OpenApiHttpFake::respond('consumer-fake'));
        Http::get('https://pets.example/v1/pets/7');

        $this->assertArrayHasKey('GET /v1/pets/{id}', OpenApiCoverageTracker::getCovered()['consumer-fake'] ?? []);
    }

    private function assertRecordedExchangesMatchSpec(): void
    {
        $recorded = Http::recorded();
        $this->assertNotEmpty($recorded);

        /** @var array{Request, Response} $pair */
        foreach ($recorded as $pair) {
            [$request, $response] = $pair;
            $this->assertPsr7ExchangeMatchesOpenApiSchema($request->toPsrRequest(), $response->toPsrResponse());
        }
    }
}
