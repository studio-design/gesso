<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit;

use const ARRAY_FILTER_USE_KEY;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Studio\Gesso\Coverage\OpenApiCoverageTracker;
use Studio\Gesso\Coverage\SdkExerciseCoverageTracker;
use Studio\Gesso\DecodedBody;
use Studio\Gesso\OpenApiFakeResponse;
use Studio\Gesso\OpenApiResponseValidator;
use Studio\Gesso\Spec\OpenApiSpecLoader;
use Studio\Gesso\Validation\Strict\StrictRequiredTracker;

use function array_filter;
use function array_keys;
use function array_map;
use function json_decode;
use function strtolower;

final class OpenApiFakeResponseTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        OpenApiSpecLoader::reset();
        OpenApiCoverageTracker::reset();
        SdkExerciseCoverageTracker::resetCurrent();
        OpenApiSpecLoader::configure(__DIR__ . '/../fixtures/specs');
    }

    protected function tearDown(): void
    {
        OpenApiSpecLoader::reset();
        OpenApiCoverageTracker::reset();
        SdkExerciseCoverageTracker::resetCurrent();
        parent::tearDown();
    }

    #[Test]
    public function example_wins_over_schema(): void
    {
        $fake = OpenApiFakeResponse::for('consumer-fake', 'GET', '/v1/pets/7');

        $this->assertSame(200, $fake->status());
        $body = $fake->body();
        $this->assertIsArray($body);
        $this->assertSame(7, $body['id']);
        $this->assertStringContainsString('"meta":{}', $fake->content());
        $this->assertStringContainsString('"tags":[]', $fake->content());
        $this->assertSame('application/json', $fake->headers()['Content-Type']);
        $this->assertSame('application/json', $fake->contentType());
    }

    #[Test]
    public function template_path_resolves_like_a_concrete_path(): void
    {
        $concrete = OpenApiFakeResponse::for('consumer-fake', 'GET', '/v1/pets/7');
        $template = OpenApiFakeResponse::for('consumer-fake', 'GET', '/v1/pets/{id}');

        $this->assertSame($concrete->status(), $template->status());
        $this->assertSame($concrete->headers(), $template->headers());
        $this->assertSame($concrete->content(), $template->content());
    }

    #[Test]
    public function first_examples_entry_is_used(): void
    {
        $fake = OpenApiFakeResponse::for('consumer-fake', 'GET', '/v1/pets/7', 404);

        $this->assertSame(404, $fake->status());
        $this->assertSame('{"title":"Pet not found","status":404}', $fake->content());
        $this->assertSame('application/problem+json', $fake->headers()['Content-Type']);
    }

    #[Test]
    public function default_status_is_lowest_declared_2xx(): void
    {
        $this->assertSame(201, OpenApiFakeResponse::for('consumer-fake', 'POST', '/v1/pets')->status());
    }

    #[Test]
    public function default_only_response_fakes_as_200(): void
    {
        $fake = OpenApiFakeResponse::for('consumer-fake', 'GET', '/v1/legacy');

        $this->assertSame(200, $fake->status());
        $this->assertResponseIsValid('GET', '/v1/legacy', $fake);
    }

    #[Test]
    public function generated_body_validates_against_the_spec(): void
    {
        $created = OpenApiFakeResponse::for('consumer-fake', 'POST', '/v1/pets');
        $this->assertIsArray($created->body());
        $this->assertResponseIsValid('POST', '/v1/pets', $created);

        $this->assertResponseIsValid('GET', '/v1/pets/7', OpenApiFakeResponse::for('consumer-fake', 'GET', '/v1/pets/7'));
        $this->assertResponseIsValid('POST', '/v1/pets', OpenApiFakeResponse::for('consumer-fake', 'POST', '/v1/pets', 422));
    }

    #[Test]
    public function generation_is_deterministic(): void
    {
        $this->assertSame(
            OpenApiFakeResponse::for('consumer-fake', 'POST', '/v1/pets')->content(),
            OpenApiFakeResponse::for('consumer-fake', 'POST', '/v1/pets')->content(),
        );
    }

    #[Test]
    public function declared_headers_are_emitted_as_strings(): void
    {
        $headers = OpenApiFakeResponse::for('consumer-fake', 'GET', '/v1/pets/7')->headers();

        $this->assertSame('req-1', $headers['X-Request-Id']);
        $this->assertMatchesRegularExpression('/^\d+$/', $headers['X-RateLimit-Remaining']);
        // The spec's `Content-Type` header entry is ignored; only the media type is sent.
        $this->assertSame(['Content-Type'], array_keys(array_filter(
            $headers,
            static fn(string $name): bool => strtolower($name) === 'content-type',
            ARRAY_FILTER_USE_KEY,
        )));
        $this->assertSame('application/json', $headers['Content-Type']);
    }

    #[Test]
    public function no_content_response_has_no_body_or_content_type(): void
    {
        $fake = OpenApiFakeResponse::for('consumer-fake', 'DELETE', '/v1/pets/7');

        $this->assertSame(204, $fake->status());
        $this->assertNull($fake->body());
        $this->assertSame('', $fake->content());
        $this->assertNull($fake->contentType());
        $this->assertArrayNotHasKey('Content-Type', $fake->headers());
        $this->assertArrayHasKey('X-Deleted-At', $fake->headers());
    }

    #[Test]
    public function non_json_example_is_sent_verbatim(): void
    {
        $fake = OpenApiFakeResponse::for('consumer-fake', 'GET', '/v1/export');

        $this->assertSame("id,name\n7,Fido\n", $fake->content());
        $this->assertSame('text/csv', $fake->headers()['Content-Type']);
    }

    #[Test]
    public function application_range_falls_back_to_json(): void
    {
        $fake = OpenApiFakeResponse::for('consumer-fake', 'GET', '/v1/anything');

        $this->assertSame('application/json', $fake->headers()['Content-Type']);
        $decoded = json_decode($fake->content(), true);
        $this->assertIsArray($decoded);
        $this->assertIsBool($decoded['ok']);
    }

    #[Test]
    public function non_json_range_requires_explicit_content_type(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('image/*');

        OpenApiFakeResponse::for('consumer-fake', 'GET', '/v1/image');
    }

    #[Test]
    public function undeclared_status_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('418');

        // `default` is declared for this operation, so 418 would resolve to
        // it; ask PUT instead, which declares only 400.
        OpenApiFakeResponse::for('consumer-fake', 'PUT', '/v1/pets/7', 418);
    }

    #[Test]
    public function unknown_operation_is_rejected(): void
    {
        foreach ([['PATCH', '/v1/pets/7'], ['GET', '/nope']] as [$method, $path]) {
            try {
                OpenApiFakeResponse::for('consumer-fake', $method, $path);
                $this->fail("{$method} {$path} should be rejected");
            } catch (InvalidArgumentException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }
    }

    #[Test]
    public function no_2xx_requires_explicit_status(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('No 2xx response');

        OpenApiFakeResponse::for('consumer-fake', 'PUT', '/v1/pets/7');
    }

    #[Test]
    public function nothing_to_fake_is_loud(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('No example and no JSON schema');

        OpenApiFakeResponse::for('consumer-fake', 'GET', '/v1/html');
    }

    #[Test]
    public function malformed_response_node_throws(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Malformed 'responses[200]'");

        OpenApiFakeResponse::for('malformed-response', 'GET', '/things');
    }

    #[Test]
    public function explicit_undeclared_media_type_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Media type 'application/json' is not declared");

        // The validator's JSON fallback would otherwise answer with text/csv.
        OpenApiFakeResponse::for('consumer-fake', 'GET', '/v1/export', 200, 'application/json');
    }

    #[Test]
    public function explicit_declared_media_type_is_selected(): void
    {
        $fake = OpenApiFakeResponse::for('consumer-fake', 'GET', '/v1/pets/7', 404, 'application/problem+json');

        $this->assertSame('application/problem+json', $fake->contentType());
    }

    #[Test]
    public function malformed_header_entry_throws(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('[response-header.X-Rate] header definition must be an object');

        OpenApiFakeResponse::for('consumer-fake', 'GET', '/v1/broken-headers');
    }

    #[Test]
    public function declared_content_length_is_left_to_the_client(): void
    {
        $this->assertArrayNotHasKey('Content-Length', OpenApiFakeResponse::for('consumer-fake', 'GET', '/v1/legacy')->headers());
    }

    #[Test]
    public function explicit_media_type_selects_its_own_entry(): void
    {
        // The validator would fall back to the application/json entry; the
        // fake answers from the application/* entry the type matches.
        $fake = OpenApiFakeResponse::for('consumer-fake', 'GET', '/v1/search', 200, 'application/vnd.pets+json');

        $this->assertSame('application/vnd.pets+json', $fake->headers()['Content-Type']);
        $this->assertSame('{"vendor":true}', $fake->content());
    }

    #[Test]
    public function required_header_without_schema_throws(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('[response-header.X-Need] required header has no schema');

        OpenApiFakeResponse::for('consumer-fake', 'GET', '/v1/headless');
    }

    #[Test]
    public function required_header_that_cannot_be_generated_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('[response-header.X-Token] required header cannot be generated from its schema; add an `example`');

        OpenApiFakeResponse::for('consumer-fake', 'GET', '/v1/patterned-header');
    }

    #[Test]
    public function empty_content_map_fakes_a_response_without_a_body(): void
    {
        $fake = OpenApiFakeResponse::for('consumer-fake', 'DELETE', '/v1/empty-content');

        $this->assertSame(204, $fake->status());
        $this->assertNull($fake->body());
        $this->assertSame('', $fake->content());
        $this->assertNull($fake->contentType());
        $this->assertResponseIsValid('DELETE', '/v1/empty-content', $fake);
    }

    #[Test]
    public function faking_records_no_coverage(): void
    {
        OpenApiFakeResponse::for('consumer-fake', 'GET', '/v1/pets/7');
        OpenApiFakeResponse::for('consumer-fake', 'POST', '/v1/pets');
        OpenApiFakeResponse::for('consumer-fake', 'GET', '/v1/anything');

        $this->assertArrayNotHasKey('consumer-fake', OpenApiCoverageTracker::getCovered());
        $this->assertSame([], SdkExerciseCoverageTracker::current()->observationsForSpecOn('consumer-fake'));
    }

    private function assertResponseIsValid(string $method, string $path, OpenApiFakeResponse $fake): void
    {
        $result = (new OpenApiResponseValidator(StrictRequiredTracker::current()))->validate(
            'consumer-fake',
            $method,
            $path,
            $fake->status(),
            DecodedBody::decodeJson($fake->content()),
            $fake->contentType(),
            array_map(static fn(string $value): array => [$value], $fake->headers()),
        );

        $this->assertTrue($result->isValid(), $result->errorMessage());
        $this->assertFalse($result->isSkipped(), (string) $result->skipReason());
    }
}
