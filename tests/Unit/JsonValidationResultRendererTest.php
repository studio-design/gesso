<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit;

use const JSON_THROW_ON_ERROR;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\Gesso\JsonValidationResultRenderer;
use Studio\Gesso\OpenApiValidationResult;
use Studio\Gesso\ValidationIssue;

use function json_decode;

class JsonValidationResultRendererTest extends TestCase
{
    #[Test]
    public function failure_serialises_issues_with_all_fields(): void
    {
        $result = OpenApiValidationResult::failure(
            ['[/name] The data (integer) must match the type: string', 'query error'],
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
                new ValidationIssue(
                    'request.parameter.query',
                    'query error',
                    method: 'POST',
                    path: '/v1/pets',
                    parameter: 'limit',
                ),
            ],
        );

        $decoded = self::decode(JsonValidationResultRenderer::render($result));

        $this->assertSame(1, $decoded['schema_version']);
        $this->assertSame('studio-design/gesso', $decoded['tool']['name']);
        $this->assertIsString($decoded['tool']['version']);
        $this->assertSame('failure', $decoded['outcome']);
        $this->assertSame(
            ['path' => '/v1/pets', 'status_code' => null, 'content_type' => 'application/json'],
            $decoded['matched'],
        );
        $this->assertNull($decoded['skip_reason']);
        $this->assertNull($decoded['reproduce_command']);
        $this->assertSame(
            [
                [
                    'category' => 'request.body',
                    'message' => '[/name] The data (integer) must match the type: string',
                    'instance_path' => '/name',
                    'keyword' => 'type',
                    'parameter' => null,
                    'method' => 'POST',
                    'path' => '/v1/pets',
                    'status_code' => null,
                    'content_type' => 'application/json',
                ],
                [
                    'category' => 'request.parameter.query',
                    'message' => 'query error',
                    'instance_path' => null,
                    'keyword' => null,
                    'parameter' => 'limit',
                    'method' => 'POST',
                    'path' => '/v1/pets',
                    'status_code' => null,
                    'content_type' => null,
                ],
            ],
            $decoded['issues'],
        );
    }

    #[Test]
    public function success_serialises_with_empty_issues(): void
    {
        $result = OpenApiValidationResult::success('/v1/pets', '200', 'application/json');

        $decoded = self::decode(JsonValidationResultRenderer::render($result));

        $this->assertSame('success', $decoded['outcome']);
        $this->assertSame(
            ['path' => '/v1/pets', 'status_code' => '200', 'content_type' => 'application/json'],
            $decoded['matched'],
        );
        $this->assertNull($decoded['skip_reason']);
        $this->assertSame([], $decoded['issues']);
    }

    #[Test]
    public function skipped_serialises_the_reason(): void
    {
        $result = OpenApiValidationResult::skipped('/v1/pets', 'undocumented 5xx', '503');

        $decoded = self::decode(JsonValidationResultRenderer::render($result));

        $this->assertSame('skipped', $decoded['outcome']);
        $this->assertSame('undocumented 5xx', $decoded['skip_reason']);
        $this->assertSame('503', $decoded['matched']['status_code']);
        $this->assertSame([], $decoded['issues']);
    }

    #[Test]
    public function legacy_failure_serialises_derived_unknown_issues(): void
    {
        // Results built without tagged issues derive one `unknown` issue per
        // error string; the JSON view must serialise those the same way.
        $result = OpenApiValidationResult::failure(['some error'], '/v1/pets', '404');

        $decoded = self::decode(JsonValidationResultRenderer::render($result));

        $this->assertCount(1, $decoded['issues']);
        $this->assertSame('unknown', $decoded['issues'][0]['category']);
        $this->assertSame('some error', $decoded['issues'][0]['message']);
        $this->assertSame('404', $decoded['issues'][0]['status_code']);
    }

    #[Test]
    public function reproduce_command_is_embedded_verbatim(): void
    {
        $result = OpenApiValidationResult::failure(['boom']);
        $command = "curl -X POST 'https://api.test/v1/pets' -H 'Authorization: <redacted>'";

        $decoded = self::decode(JsonValidationResultRenderer::render($result, $command));

        $this->assertSame($command, $decoded['reproduce_command']);
    }

    #[Test]
    public function document_is_pretty_printed_with_trailing_newline(): void
    {
        $document = JsonValidationResultRenderer::render(OpenApiValidationResult::success());

        $this->assertStringEndsWith("}\n", $document);
        $this->assertStringContainsString("\n    \"schema_version\": 1", $document);
        // Unescaped slashes keep categories and pointers readable.
        $this->assertStringNotContainsString('\\/', $document);
    }

    /**
     * @return array<string, mixed>
     */
    private static function decode(string $document): array
    {
        /** @var array<string, mixed> */
        return json_decode($document, true, flags: JSON_THROW_ON_ERROR);
    }
}
