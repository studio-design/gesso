<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit\Symfony;

use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\Gesso\Coverage\OpenApiCoverageTracker;
use Studio\Gesso\OpenApiResponseValidator;
use Studio\Gesso\Spec\OpenApiSpecLoader;
use Studio\Gesso\Symfony\OpenApiHttpFake;
use Studio\Gesso\Validation\Strict\StrictRequiredTracker;
use Symfony\Component\HttpClient\MockHttpClient;

final class OpenApiHttpFakeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        OpenApiSpecLoader::reset();
        OpenApiCoverageTracker::reset();
        OpenApiSpecLoader::configure(__DIR__ . '/../../fixtures/specs');
    }

    protected function tearDown(): void
    {
        OpenApiSpecLoader::reset();
        OpenApiCoverageTracker::reset();
        parent::tearDown();
    }

    #[Test]
    public function mock_client_answers_from_the_spec(): void
    {
        $response = (new MockHttpClient(OpenApiHttpFake::respond('consumer-fake')))
            ->request('GET', 'https://pets.example/v1/pets/7');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(7, $response->toArray()['id']);
        $this->assertSame(['req-1'], $response->getHeaders()['x-request-id']);
        $this->assertArrayHasKey('GET /v1/pets/{id}', OpenApiCoverageTracker::getCovered()['consumer-fake'] ?? []);
    }

    #[Test]
    public function json_option_body_is_validated(): void
    {
        $client = new MockHttpClient(OpenApiHttpFake::respond('consumer-fake'));

        $response = $client->request('POST', 'https://pets.example/v1/pets?idempotency=k', ['json' => ['name' => 'Fido']]);
        $this->assertSame(201, $response->getStatusCode());
        $result = (new OpenApiResponseValidator(StrictRequiredTracker::current()))->validate(
            'consumer-fake',
            'POST',
            '/v1/pets',
            201,
            $response->toArray(),
            $response->getHeaders()['content-type'][0],
        );
        $this->assertTrue($result->isValid(), $result->errorMessage());

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('OpenAPI fake rejected outgoing request POST /v1/pets (spec: consumer-fake)');
        $client->request('POST', 'https://pets.example/v1/pets?idempotency=k', ['json' => ['nme' => 'x']]);
    }

    #[Test]
    public function missing_required_query_fails(): void
    {
        $client = new MockHttpClient(OpenApiHttpFake::respond('consumer-fake'));

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('idempotency');
        $client->request('POST', 'https://pets.example/v1/pets', ['json' => ['name' => 'Fido']]);
    }

    #[Test]
    public function status_override_applies(): void
    {
        $client = new MockHttpClient(OpenApiHttpFake::respond('consumer-fake', ['POST /v1/pets' => 422]));

        $response = $client->request('POST', 'https://pets.example/v1/pets?idempotency=k', ['json' => ['name' => 'Fido']]);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame(['application/problem+json'], $response->getHeaders(false)['content-type']);
    }

    #[Test]
    public function no_content_response_has_empty_body(): void
    {
        $response = (new MockHttpClient(OpenApiHttpFake::respond('consumer-fake')))
            ->request('DELETE', 'https://pets.example/v1/pets/7');

        $this->assertSame(204, $response->getStatusCode());
        $this->assertSame('', $response->getContent(false));
    }
}
