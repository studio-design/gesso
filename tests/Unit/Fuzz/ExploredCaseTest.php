<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit\Fuzz;

use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;
use Studio\Gesso\Fuzz\ExploredCase;
use Studio\Gesso\Fuzz\FailureReducer;
use Studio\Gesso\HttpMethod;

class ExploredCaseTest extends TestCase
{
    #[Test]
    public function rejects_empty_matched_path(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('non-empty matchedPath');

        new ExploredCase(
            body: null,
            query: [],
            headers: [],
            pathParams: [],
            method: HttpMethod::GET,
            matchedPath: '',
        );
    }

    #[Test]
    public function exposes_provided_fields(): void
    {
        $case = new ExploredCase(
            body: ['name' => 'Snowy'],
            query: ['dryRun' => true],
            headers: ['X-Trace' => 'abc'],
            pathParams: ['petId' => 7],
            method: HttpMethod::POST,
            matchedPath: '/v1/pets',
        );

        $this->assertSame(['name' => 'Snowy'], $case->body);
        $this->assertSame(['dryRun' => true], $case->query);
        $this->assertSame(['X-Trace' => 'abc'], $case->headers);
        $this->assertSame(['petId' => 7], $case->pathParams);
        $this->assertSame(HttpMethod::POST, $case->method);
        $this->assertSame('/v1/pets', $case->matchedPath);
    }

    #[Test]
    public function renders_php_and_curl_reproduction_output(): void
    {
        $case = new ExploredCase(
            body: ['name' => 'Snowy'],
            query: ['dryRun' => true],
            headers: ['X-Trace' => 'abc'],
            pathParams: ['petId' => 7],
            method: HttpMethod::POST,
            matchedPath: '/v1/pets/{petId}',
            seed: 42,
            caseIndex: 2,
        );

        $this->assertStringContainsString('cases: 3, seed: 42', $case->replaySnippet('petstore'));
        $this->assertStringContainsString("curl -X POST 'https://api.example.test/v1/pets/7?", $case->curlSnippet('https://api.example.test'));
        $this->assertStringContainsString("--data '{\"name\":\"Snowy\"}'", $case->curlSnippet());
    }

    #[Test]
    public function builds_a_concrete_uri_with_encoded_path_and_query_values(): void
    {
        $case = new ExploredCase(
            body: null,
            query: [
                'search' => 'snowy owl',
                'page' => 2,
                'active' => true,
                'filters' => ['archived' => false],
            ],
            headers: [],
            pathParams: ['petId' => 'pet/7'],
            method: HttpMethod::GET,
            matchedPath: '/v1/pets/{petId}',
        );

        $expectedQuery = 'search=snowy+owl&page=2&active=true&filters%5Barchived%5D=false';

        $this->assertSame('/api/v1/pets/pet%2F7?' . $expectedQuery, $case->uri('/api'));
        $this->assertStringContainsString(
            "'https://api.example.test/v1/pets/pet%2F7?{$expectedQuery}'",
            $case->curlSnippet('https://api.example.test'),
        );
    }

    #[Test]
    public function converts_json_object_and_array_bodies_for_array_typed_http_helpers(): void
    {
        $object = new stdClass();
        $object->name = 'Snowy';
        $object->details = (object) ['age' => 3];

        $objectCase = $this->caseWithBody($object);
        $arrayCase = $this->caseWithBody(['name' => 'Snowy']);

        $this->assertSame(['name' => 'Snowy', 'details' => ['age' => 3]], $objectCase->bodyAsArray());
        $this->assertSame(['name' => 'Snowy'], $arrayCase->bodyAsArray());
        $this->assertNull($this->caseWithBody(null)->bodyAsArray());
        $this->assertSame([], $this->caseWithBody(new stdClass())->bodyAsArray());
    }

    #[Test]
    public function rejects_scalar_bodies_for_array_typed_http_helpers(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('requires a JSON object or array body');

        $this->caseWithBody('scalar')->bodyAsArray();
    }

    #[Test]
    public function reducer_preserves_the_original_failure_classification(): void
    {
        $case = new ExploredCase(
            body: ['trigger' => true, 'noiseA' => 1, 'noiseB' => 2],
            query: [],
            headers: [],
            pathParams: [],
            method: HttpMethod::POST,
            matchedPath: '/v1/pets',
        );

        $reduced = FailureReducer::reduce(
            $case,
            static fn(ExploredCase $candidate): ?string => ($candidate->body['trigger'] ?? false) === true ? 'status:500' : null,
        );

        $this->assertSame(['trigger' => true], $reduced->body);
    }

    private function caseWithBody(mixed $body): ExploredCase
    {
        return new ExploredCase(
            body: $body,
            query: [],
            headers: [],
            pathParams: [],
            method: HttpMethod::POST,
            matchedPath: '/v1/pets',
        );
    }
}
