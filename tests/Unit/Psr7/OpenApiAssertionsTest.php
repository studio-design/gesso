<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Tests\Unit\Psr7;

use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\OpenApiContractTesting\Coverage\OpenApiCoverageTracker;
use Studio\OpenApiContractTesting\Psr7\OpenApiAssertions;
use Studio\OpenApiContractTesting\Spec\OpenApiSpecLoader;

final class OpenApiAssertionsTest extends TestCase
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
        OpenApiSpecLoader::reset();
        OpenApiCoverageTracker::reset();
        parent::tearDown();
    }

    #[Test]
    public function asserts_a_psr7_response_for_an_explicit_operation(): void
    {
        $response = new Response(
            201,
            ['Content-Type' => 'application/json', 'X-Trace' => 'trace-1'],
            '{"id":42}',
        );

        $this->assertPsr7ResponseForOperationMatchesOpenApiSchema('POST', '/widgets/42', $response);
    }

    #[Test]
    public function assertion_failure_includes_the_operation_and_spec(): void
    {
        $request = new Request('GET', 'https://example.test/body/scalar');
        $response = new Response(200, ['Content-Type' => 'application/json'], '"wrong"');

        try {
            $this->assertPsr7ResponseMatchesOpenApiSchema($request, $response);
            $this->fail('Expected the PSR-7 assertion to fail.');
        } catch (AssertionFailedError $e) {
            $this->assertStringContainsString('GET /body/scalar', $e->getMessage());
            $this->assertStringContainsString('spec: psr7', $e->getMessage());
        }
    }

    protected function openApiSpec(): string
    {
        return 'psr7';
    }
}
