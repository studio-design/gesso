<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit\Psr7;

use const JSON_THROW_ON_ERROR;

use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\Gesso\Coverage\OpenApiCoverageTracker;
use Studio\Gesso\Psr7\OpenApiAssertions;
use Studio\Gesso\Spec\OpenApiSpecLoader;
use Studio\Gesso\ValidationOutput;
use Studio\Gesso\ValidationOutputFormat;

use function explode;
use function json_decode;
use function putenv;
use function substr_count;

final class OpenApiAssertionsJsonOutputTest extends TestCase
{
    use OpenApiAssertions;

    protected function setUp(): void
    {
        parent::setUp();
        OpenApiSpecLoader::reset();
        OpenApiCoverageTracker::reset();
        putenv('OPENAPI_VALIDATION_OUTPUT');
        ValidationOutput::reset();
        OpenApiSpecLoader::configure(__DIR__ . '/../../fixtures/specs');
    }

    protected function tearDown(): void
    {
        OpenApiSpecLoader::reset();
        OpenApiCoverageTracker::reset();
        putenv('OPENAPI_VALIDATION_OUTPUT');
        ValidationOutput::reset();
        parent::tearDown();
    }

    #[Test]
    public function response_assertion_failure_renders_the_json_document_after_the_header(): void
    {
        ValidationOutput::use(ValidationOutputFormat::Json);
        $request = new Request('GET', 'https://example.test/body/scalar');
        $response = new Response(200, ['Content-Type' => 'application/json'], '"wrong"');

        try {
            $this->assertPsr7ResponseMatchesOpenApiSchema($request, $response);
            $this->fail('Expected the PSR-7 assertion to fail.');
        } catch (AssertionFailedError $e) {
            [$header, $document] = explode("\n", $e->getMessage(), 2);

            $this->assertSame(
                'OpenAPI PSR-7 response validation failed for GET /body/scalar (spec: psr7):',
                $header,
            );

            /** @var array<string, mixed> $decoded */
            $decoded = json_decode($document, true, 512, JSON_THROW_ON_ERROR);
            $this->assertSame(1, $decoded['schema_version']);
            $this->assertSame('failure', $decoded['outcome']);
            $this->assertSame('response.body', $decoded['issues'][0]['category']);
            $this->assertStringContainsString(
                "curl -X GET 'https://example.test/body/scalar'",
                $decoded['reproduce_command'],
            );
        }
    }

    #[Test]
    public function json_mode_is_selected_by_the_environment_variable(): void
    {
        putenv('OPENAPI_VALIDATION_OUTPUT=json');
        $request = new Request('GET', 'https://example.test/body/scalar');
        $response = new Response(200, ['Content-Type' => 'application/json'], '"wrong"');

        try {
            $this->assertPsr7ResponseMatchesOpenApiSchema($request, $response);
            $this->fail('Expected the PSR-7 assertion to fail.');
        } catch (AssertionFailedError $e) {
            $this->assertStringContainsString('"schema_version": 1', $e->getMessage());
            $this->assertStringNotContainsString("\nReproduce: ", $e->getMessage());
        }
    }

    #[Test]
    public function exchange_failure_emits_one_labelled_document_per_failing_side(): void
    {
        ValidationOutput::use(ValidationOutputFormat::Json);
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

        try {
            $this->assertPsr7ExchangeMatchesOpenApiSchema($request, $response);
            $this->fail('Expected the PSR-7 exchange assertion to fail.');
        } catch (AssertionFailedError $e) {
            $message = $e->getMessage();

            [$header, $rest] = explode("\n", $message, 2);
            $this->assertSame(
                'OpenAPI PSR-7 exchange validation failed for POST /widgets/42 (spec: psr7):',
                $header,
            );

            $this->assertStringContainsString("[request]\n{", $message);
            $this->assertStringContainsString("[response]\n{", $message);

            [, $requestBlock] = explode("[request]\n", $rest, 2);
            [$requestDocument, $responseBlock] = explode("[response]\n", $requestBlock, 2);

            /** @var array<string, mixed> $requestDecoded */
            $requestDecoded = json_decode($requestDocument, true, 512, JSON_THROW_ON_ERROR);
            /** @var array<string, mixed> $responseDecoded */
            $responseDecoded = json_decode($responseBlock, true, 512, JSON_THROW_ON_ERROR);

            $this->assertSame('failure', $requestDecoded['outcome']);
            $this->assertSame('failure', $responseDecoded['outcome']);
            $this->assertSame('response.body', $responseDecoded['issues'][0]['category']);
            $this->assertStringContainsString('curl -X POST', $requestDecoded['reproduce_command']);
            $this->assertSame($requestDecoded['reproduce_command'], $responseDecoded['reproduce_command']);
        }
    }

    #[Test]
    public function exchange_failure_with_one_failing_side_emits_only_that_block(): void
    {
        ValidationOutput::use(ValidationOutputFormat::Json);
        $request = new Request('GET', 'https://example.test/body/scalar');
        $response = new Response(200, ['Content-Type' => 'application/json'], '"wrong"');

        try {
            $this->assertPsr7ExchangeMatchesOpenApiSchema($request, $response);
            $this->fail('Expected the PSR-7 exchange assertion to fail.');
        } catch (AssertionFailedError $e) {
            $this->assertStringNotContainsString("[request]\n", $e->getMessage());
            $this->assertStringContainsString("[response]\n{", $e->getMessage());
        }
    }

    #[Test]
    public function exchange_failure_keeps_the_legacy_text_shape_by_default(): void
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

        try {
            $this->assertPsr7ExchangeMatchesOpenApiSchema($request, $response);
            $this->fail('Expected the PSR-7 exchange assertion to fail.');
        } catch (AssertionFailedError $e) {
            $message = $e->getMessage();

            $this->assertStringContainsString(
                "OpenAPI PSR-7 exchange validation failed for POST /widgets/42 (spec: psr7):\n",
                $message,
            );
            $this->assertStringContainsString('[request] ', $message);
            $this->assertStringContainsString('[response] ', $message);
            $this->assertSame(1, substr_count($message, 'Reproduce: '));
            $this->assertStringNotContainsString('schema_version', $message);
        }
    }

    protected function openApiSpec(): string
    {
        return 'psr7';
    }
}
