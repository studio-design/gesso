<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit\Internal;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\Gesso\Internal\CurlCommandFormatter;

use function str_contains;

final class CurlCommandFormatterTest extends TestCase
{
    #[Test]
    public function test_renders_method_and_quoted_uri_without_headers_or_body(): void
    {
        $command = CurlCommandFormatter::format('GET', '/v1/pets?limit=5', [], null, null);

        $this->assertSame("curl -X GET '/v1/pets?limit=5'", $command);
    }

    #[Test]
    public function test_renders_headers_including_list_values(): void
    {
        $command = CurlCommandFormatter::format(
            'GET',
            'https://api.example.test/v1/pets',
            [
                'Accept' => 'application/json',
                'X-Trace' => ['abc', 'def'],
            ],
            null,
            null,
        );

        $this->assertStringContainsString("-H 'Accept: application/json'", $command);
        $this->assertStringContainsString("-H 'X-Trace: abc'", $command);
        $this->assertStringContainsString("-H 'X-Trace: def'", $command);
    }

    #[Test]
    public function test_redacts_sensitive_headers_by_default(): void
    {
        $command = CurlCommandFormatter::format(
            'GET',
            '/v1/pets',
            [
                'Authorization' => 'Bearer real-token',
                'cookie' => 'session=abc',
                'Proxy-Authorization' => 'Basic dXNlcg==',
                'X-Api-Key' => 'k-123',
                'X-Auth-Token' => 't-456',
                'Client-Secret' => 's-789',
                'Accept' => 'application/json',
            ],
            null,
            null,
        );

        $this->assertStringContainsString("-H 'Authorization: <redacted>'", $command);
        $this->assertStringContainsString("-H 'cookie: <redacted>'", $command);
        $this->assertStringContainsString("-H 'Proxy-Authorization: <redacted>'", $command);
        $this->assertStringContainsString("-H 'X-Api-Key: <redacted>'", $command);
        $this->assertStringContainsString("-H 'X-Auth-Token: <redacted>'", $command);
        $this->assertStringContainsString("-H 'Client-Secret: <redacted>'", $command);
        $this->assertStringContainsString("-H 'Accept: application/json'", $command);
        $this->assertFalse(str_contains($command, 'real-token'));
        $this->assertFalse(str_contains($command, 'session=abc'));
    }

    #[Test]
    public function test_quotes_methods_containing_shell_metacharacters(): void
    {
        $injected = CurlCommandFormatter::format('GET; id', '/v1/pets', [], null, null);
        $custom = CurlCommandFormatter::format('X-CUSTOM', '/v1/pets', [], null, null);

        $this->assertStringContainsString("curl -X 'GET; id' ", $injected);
        $this->assertFalse(str_contains($injected, 'curl -X GET; id'));
        $this->assertStringContainsString('curl -X X-CUSTOM ', $custom);
    }

    #[Test]
    public function test_redacts_sensitive_query_parameter_values(): void
    {
        $command = CurlCommandFormatter::format(
            'GET',
            'https://api.example.test/v1/pets?api_key=real-secret&access_token=tok-1&limit=5',
            [],
            null,
            null,
        );

        $this->assertStringContainsString('api_key=<redacted>', $command);
        $this->assertStringContainsString('access_token=<redacted>', $command);
        $this->assertStringContainsString('limit=5', $command);
        $this->assertFalse(str_contains($command, 'real-secret'));
        $this->assertFalse(str_contains($command, 'tok-1'));
    }

    #[Test]
    public function test_query_redaction_handles_encoded_names_and_valueless_pairs(): void
    {
        $command = CurlCommandFormatter::format(
            'GET',
            '/v1/pets?client%5Fsecret=s-1&flag&x=1',
            [],
            null,
            null,
        );

        $this->assertStringContainsString('client%5Fsecret=<redacted>', $command);
        $this->assertStringContainsString('flag', $command);
        $this->assertStringContainsString('x=1', $command);
        $this->assertFalse(str_contains($command, 's-1'));
    }

    #[Test]
    public function test_redaction_can_be_disabled(): void
    {
        $command = CurlCommandFormatter::format(
            'GET',
            '/v1/pets',
            ['Authorization' => 'Bearer real-token'],
            null,
            null,
            redact: false,
        );

        $this->assertStringContainsString("-H 'Authorization: Bearer real-token'", $command);
        $this->assertFalse(str_contains($command, '<redacted>'));
    }

    #[Test]
    public function test_appends_data_for_json_content_types(): void
    {
        $plain = CurlCommandFormatter::format(
            'POST',
            '/v1/pets',
            [],
            '{"name":"Snowy"}',
            'application/json; charset=utf-8',
        );
        $vendor = CurlCommandFormatter::format(
            'POST',
            '/v1/pets',
            [],
            '{"name":"Snowy"}',
            'application/vnd.example+json',
        );

        $this->assertStringContainsString('--data \'{"name":"Snowy"}\'', $plain);
        $this->assertStringContainsString('--data \'{"name":"Snowy"}\'', $vendor);
    }

    #[Test]
    public function test_omits_data_for_non_json_body_null_body_and_empty_body(): void
    {
        $multipart = CurlCommandFormatter::format('POST', '/v1/pets', [], 'raw-bytes', 'multipart/form-data');
        $nullBody = CurlCommandFormatter::format('POST', '/v1/pets', [], null, 'application/json');
        $emptyBody = CurlCommandFormatter::format('POST', '/v1/pets', [], '', 'application/json');

        $this->assertFalse(str_contains($multipart, '--data'));
        $this->assertFalse(str_contains($nullBody, '--data'));
        $this->assertFalse(str_contains($emptyBody, '--data'));
    }

    #[Test]
    public function test_output_is_a_single_line_even_with_multiline_body(): void
    {
        $command = CurlCommandFormatter::format(
            'POST',
            '/v1/pets',
            ['X-Note' => "line1\nline2"],
            "{\n  \"name\": \"Snowy\"\n}",
            'application/json',
        );

        $this->assertFalse(str_contains($command, "\n"));
    }

    #[Test]
    public function test_scalar_header_values_are_stringified(): void
    {
        $command = CurlCommandFormatter::format(
            'GET',
            '/v1/pets',
            ['X-Count' => 3, 'X-Flag' => true, 'X-Null' => null],
            null,
            null,
        );

        $this->assertStringContainsString("-H 'X-Count: 3'", $command);
        $this->assertStringContainsString("-H 'X-Flag: 1'", $command);
        $this->assertStringContainsString("-H 'X-Null: '", $command);
    }
}
