<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit\Internal;

use const JSON_THROW_ON_ERROR;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\Gesso\Internal\FailureOutput;
use Studio\Gesso\OpenApiValidationResult;
use Studio\Gesso\ValidationIssue;
use Studio\Gesso\ValidationOutput;
use Studio\Gesso\ValidationOutputFormat;

use function explode;
use function json_decode;
use function putenv;
use function str_starts_with;

class FailureOutputTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        putenv('OPENAPI_VALIDATION_OUTPUT');
        ValidationOutput::reset();
    }

    protected function tearDown(): void
    {
        putenv('OPENAPI_VALIDATION_OUTPUT');
        ValidationOutput::reset();

        parent::tearDown();
    }

    private static function failureResult(): OpenApiValidationResult
    {
        return OpenApiValidationResult::failure(
            ['[/name] The data (integer) must match the type: string'],
            '/v1/pets',
            matchedContentType: 'application/json',
            issues: [
                new ValidationIssue(
                    'request.body',
                    '[/name] The data (integer) must match the type: string',
                    instancePath: '/name',
                    keyword: 'type',
                    method: 'POST',
                    path: '/v1/pets',
                    contentType: 'application/json',
                ),
            ],
        );
    }

    #[Test]
    public function text_mode_matches_the_legacy_shape_byte_for_byte(): void
    {
        $message = FailureOutput::compose(
            'OpenAPI request validation failed for POST /v1/pets (spec: front)',
            self::failureResult(),
            static fn(): string => "curl -X POST 'http://localhost/v1/pets'",
        );

        $this->assertSame(
            "OpenAPI request validation failed for POST /v1/pets (spec: front):\n"
            . "[/name] The data (integer) must match the type: string\n"
            . "Reproduce: curl -X POST 'http://localhost/v1/pets'",
            $message,
        );
    }

    #[Test]
    public function json_mode_emits_the_header_line_followed_by_the_rendered_document(): void
    {
        ValidationOutput::use(ValidationOutputFormat::Json);

        $message = FailureOutput::compose(
            'OpenAPI request validation failed for POST /v1/pets (spec: front)',
            self::failureResult(),
            static fn(): string => "curl -X POST 'http://localhost/v1/pets'",
        );

        [$header, $document] = explode("\n", $message, 2);

        $this->assertSame('OpenAPI request validation failed for POST /v1/pets (spec: front):', $header);
        $this->assertTrue(str_starts_with($document, '{'));

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($document, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(1, $decoded['schema_version']);
        $this->assertSame('failure', $decoded['outcome']);
        $this->assertSame("curl -X POST 'http://localhost/v1/pets'", $decoded['reproduce_command']);
        $this->assertSame('request.body', $decoded['issues'][0]['category']);
        $this->assertSame('/name', $decoded['issues'][0]['instance_path']);
    }

    #[Test]
    public function json_mode_follows_the_environment_variable(): void
    {
        putenv('OPENAPI_VALIDATION_OUTPUT=json');

        $message = FailureOutput::compose(
            'OpenAPI request validation failed for POST /v1/pets (spec: front)',
            self::failureResult(),
            static fn(): string => 'curl',
        );

        $this->assertStringContainsString('"schema_version": 1', $message);
    }
}
