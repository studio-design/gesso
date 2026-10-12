<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit\Arazzo;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;
use Studio\Gesso\Arazzo\ArazzoStepRequest;
use Studio\Gesso\DecodedBody;

final class ArazzoStepRequestTest extends TestCase
{
    #[Test]
    public function uri_encodes_path_parameters_and_query_values(): void
    {
        $request = $this->request(
            pathParams: ['petId' => 'a b/c'],
            query: ['active' => true, 'tags' => ['x', 'y'], 'limit' => 2],
        );

        $this->assertSame('https://api.test/pets/a%20b%2Fc?active=true&tags%5B0%5D=x&tags%5B1%5D=y&limit=2', $request->uri('https://api.test'));
    }

    #[Test]
    public function body_as_json_preserves_empty_objects_and_absence(): void
    {
        $this->assertSame('{"meta":{},"list":[],"f":1.0}', $this->request(body: DecodedBody::fromJsonValue((object) ['meta' => new stdClass(), 'list' => [], 'f' => 1.0]))->bodyAsJson());
        $this->assertNull($this->request()->bodyAsJson());
    }

    #[Test]
    public function curl_snippet_redacts_authorization_by_default(): void
    {
        $request = $this->request(headers: ['Authorization' => 'Bearer secret'], body: DecodedBody::fromJsonValue((object) ['name' => 'Rex']), contentType: 'application/json');

        $snippet = $request->curlSnippet('https://api.test');
        $this->assertStringContainsString('<redacted>', $snippet);
        $this->assertStringNotContainsString('secret', $snippet);
        $this->assertStringContainsString('{"name":"Rex"}', $snippet);
        $this->assertStringContainsString('secret', $request->curlSnippet('https://api.test', redactSensitiveHeaders: false));
    }

    /**
     * @param array<string, mixed> $pathParams
     * @param array<string, mixed> $query
     * @param array<string, mixed> $headers
     */
    private function request(
        array $pathParams = ['petId' => 1],
        array $query = [],
        array $headers = [],
        ?DecodedBody $body = null,
        ?string $contentType = null,
    ): ArazzoStepRequest {
        return new ArazzoStepRequest('step', 'spec', 'getPet', 'GET', '/pets/{petId}', $pathParams, $query, $headers, [], $body ?? DecodedBody::absent(), $contentType);
    }
}
