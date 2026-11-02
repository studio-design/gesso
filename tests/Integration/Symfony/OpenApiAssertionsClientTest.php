<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Integration\Symfony;

use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\Gesso\Attribute\OpenApiSpec;
use Studio\Gesso\Coverage\OpenApiCoverageTracker;
use Studio\Gesso\Spec\OpenApiSpecLoader;
use Studio\Gesso\Symfony\OpenApiAssertions;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelBrowser;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Exercises {@see OpenApiAssertions::assertOpenApiExchange()}
 * against a real {@see HttpKernelBrowser} round-trip. The kernel is a tiny
 * stand-in (no framework bundle / container) that echoes a fixed response,
 * so the test confirms the client wiring — `getRequest()` / `getResponse()`
 * extraction — without booting a full Symfony application.
 */
#[OpenApiSpec('petstore-3.0')]
final class OpenApiAssertionsClientTest extends TestCase
{
    use OpenApiAssertions;

    protected function setUp(): void
    {
        parent::setUp();
        OpenApiSpecLoader::reset();
        OpenApiSpecLoader::configure(__DIR__ . '/../../fixtures/specs');
        OpenApiCoverageTracker::reset();
    }

    protected function tearDown(): void
    {
        OpenApiSpecLoader::reset();
        OpenApiCoverageTracker::reset();
        parent::tearDown();
    }

    #[Test]
    public function client_request_and_response_pass_validation(): void
    {
        $client = $this->clientReturning(
            new JsonResponse(['data' => [['id' => 1, 'name' => 'Fido', 'tag' => null]]]),
        );
        $client->request('GET', '/v1/pets');

        $this->assertOpenApiExchange($client);

        $covered = OpenApiCoverageTracker::getCovered();
        $this->assertArrayHasKey('petstore-3.0', $covered);
        $this->assertArrayHasKey('GET /v1/pets', $covered['petstore-3.0']);
    }

    #[Test]
    public function client_off_schema_response_fails_validation(): void
    {
        $client = $this->clientReturning(new JsonResponse(['wrong_key' => 'value']));
        $client->request('GET', '/v1/pets');

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('OpenAPI schema validation failed for GET /v1/pets');

        $this->assertOpenApiExchange($client);
    }

    #[Test]
    public function client_without_a_request_fails_with_actionable_message(): void
    {
        // No $client->request(...) call — getRequest() throws BadMethodCallException,
        // which the adapter converts into a clean contract-test failure.
        $client = $this->clientReturning(new JsonResponse(['data' => []]));

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('Call $client->request(...) before asserting');

        $this->assertOpenApiExchange($client);
    }

    #[Test]
    public function client_request_failure_takes_precedence_over_response(): void
    {
        // POST /v1/pets requires a JSON body; the browser sends none, so the
        // request fails validation first — before the off-schema response.
        $client = $this->clientReturning(new JsonResponse(['wrong_key' => 'value']));
        $client->request('POST', '/v1/pets');

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('OpenAPI request validation failed for POST /v1/pets');

        $this->assertOpenApiExchange($client);
    }

    #[Test]
    public function client_forwards_extra_skip_response_codes(): void
    {
        // The response is an undocumented 409 for GET /v1/pets; passing it as
        // an extra skip code through the client wrapper suppresses validation.
        $client = $this->clientReturning(new JsonResponse(['wrong_key' => 'value'], 409));
        $client->request('GET', '/v1/pets');

        $this->assertOpenApiExchange($client, ['409']);
    }

    private function clientReturning(Response $response): HttpKernelBrowser
    {
        $kernel = new class ($response) implements HttpKernelInterface {
            public function __construct(private readonly Response $response) {}

            public function handle(
                Request $request,
                int $type = self::MAIN_REQUEST,
                bool $catch = true,
            ): Response {
                return $this->response;
            }
        };

        return new HttpKernelBrowser($kernel);
    }
}
