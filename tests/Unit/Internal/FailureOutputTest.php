<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit\Internal;

use const JSON_THROW_ON_ERROR;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\Gesso\Internal\FailureOutput;
use Studio\Gesso\Internal\LegacyIdentity;
use Studio\Gesso\OpenApiValidationResult;
use Studio\Gesso\Validation\Support\ValidationPolicyDefaults;
use Studio\Gesso\ValidationIssue;
use Studio\Gesso\ValidationOutput;
use Studio\Gesso\ValidationOutputFormat;

use function explode;
use function json_decode;
use function putenv;

class FailureOutputTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        LegacyIdentity::resetEnvForTesting('GESSO_VALIDATION_FORMAT');
        ValidationOutput::reset();
    }

    protected function tearDown(): void
    {
        LegacyIdentity::resetEnvForTesting('GESSO_VALIDATION_FORMAT');
        ValidationOutput::reset();
        ValidationPolicyDefaults::configure();

        parent::tearDown();
    }

    #[Test]
    public function text_mode_leads_each_issue_line_with_its_failure_code(): void
    {
        $message = FailureOutput::compose(
            'OpenAPI request validation failed for POST /v1/pets (spec: front)',
            self::failureResult(),
            static fn(): string => "curl -X POST 'http://localhost/v1/pets'",
        );

        $this->assertSame(
            "OpenAPI request validation failed for POST /v1/pets (spec: front):\n"
            . "[GSO112] [/name] The data (integer) must match the type: string\n"
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
        $this->assertStringStartsWith('{', $document);

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
        putenv('GESSO_VALIDATION_FORMAT=json');

        $message = FailureOutput::compose(
            'OpenAPI request validation failed for POST /v1/pets (spec: front)',
            self::failureResult(),
            static fn(): string => 'curl',
        );

        $this->assertStringContainsString('"schema_version": 1', $message);
    }

    #[Test]
    public function agent_mode_puts_location_and_schema_context_on_each_issue_line(): void
    {
        ValidationOutput::use(ValidationOutputFormat::Agent);

        $message = FailureOutput::compose(
            'OpenAPI response validation failed for GET /v1/pets (spec: front)',
            OpenApiValidationResult::failure(
                ["[/0/name] The data (integer)\nmust match the type: string"],
                '/v1/pets',
                issues: [
                    new ValidationIssue(
                        'response.body',
                        "[/0/name] The data (integer)\nmust match the type: string",
                        instancePath: '/0/name',
                        keyword: 'type',
                        method: 'GET',
                        path: '/v1/pets',
                        statusCode: '200',
                        contentType: 'application/json',
                        schemaPointer: '/items/properties/name/type',
                        actual: '1',
                        expected: '"string"',
                    ),
                ],
            ),
            static fn(): string => "curl -X GET 'http://localhost/v1/pets'",
        );

        $this->assertSame(
            "OpenAPI response validation failed for GET /v1/pets (spec: front):\n"
            . '[GSO212] response GET /v1/pets 200 application/json schema=/items/properties/name/type actual=1 expected="string" '
            . "[/0/name] The data (integer) must match the type: string\n"
            . "Reproduce: curl -X GET 'http://localhost/v1/pets'\n"
            . 'Explain: vendor/bin/gesso explain GSO212',
            $message,
        );
    }

    #[Test]
    public function agent_mode_labels_each_side_of_an_exchange(): void
    {
        ValidationOutput::use(ValidationOutputFormat::Agent);

        $message = FailureOutput::composeExchange(
            'OpenAPI PSR-7 exchange validation failed for POST /v1/pets (spec: front)',
            ['request' => self::failureResult(), 'response' => OpenApiValidationResult::success('/v1/pets')],
            static fn(): string => 'curl',
        );

        $this->assertSame(
            "OpenAPI PSR-7 exchange validation failed for POST /v1/pets (spec: front):\n"
            . "[request] [GSO112] request POST /v1/pets application/json [/name] The data (integer) must match the type: string\n"
            . "Reproduce: curl\n"
            . 'Explain: vendor/bin/gesso explain GSO112',
            $message,
        );
    }

    #[Test]
    public function text_mode_reports_how_many_issues_exceed_max_errors(): void
    {
        ValidationPolicyDefaults::configure(maxErrors: 2);

        $message = FailureOutput::compose('Header', self::manyIssues(5), static fn(): string => 'curl');

        $this->assertSame(
            "Header:\n[GSO112] [/a0] bad\n[GSO112] [/a1] bad\n... 3 more\nReproduce: curl",
            $message,
        );
    }

    #[Test]
    public function agent_mode_reports_how_many_issues_exceed_max_errors(): void
    {
        ValidationOutput::use(ValidationOutputFormat::Agent);
        ValidationPolicyDefaults::configure(maxErrors: 1);

        $lines = explode("\n", FailureOutput::compose('Header', self::manyIssues(3), static fn(): string => 'curl'));

        $this->assertSame('... 2 more', $lines[2]);
        $this->assertCount(5, $lines);
    }

    #[Test]
    public function max_errors_zero_renders_every_issue(): void
    {
        ValidationPolicyDefaults::configure(maxErrors: 0);

        $message = FailureOutput::compose('Header', self::manyIssues(25), static fn(): string => 'curl');

        $this->assertCount(27, explode("\n", $message));
    }

    private static function manyIssues(int $count): OpenApiValidationResult
    {
        $errors = [];
        $issues = [];
        for ($i = 0; $i < $count; $i++) {
            $errors[] = "[/a{$i}] bad";
            $issues[] = new ValidationIssue('request.body', "[/a{$i}] bad", instancePath: "/a{$i}", keyword: 'type');
        }

        return OpenApiValidationResult::failure($errors, '/v1/pets', issues: $issues);
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
}
