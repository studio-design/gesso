<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit\Validation\Response;

use LogicException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\Gesso\OpenApiVersion;
use Studio\Gesso\Spec\OpenApiSchemaDialect;
use Studio\Gesso\Spec\OpenApiSpecLoader;
use Studio\Gesso\Validation\Response\ResponseSchemaResolutionOutcome;
use Studio\Gesso\Validation\Response\ResponseSchemaResolver;
use Studio\Gesso\Validation\Support\DiscriminatorEnforcement;

/**
 * Shared response-schema resolution (issue #442): one implementation feeds
 * both the response validator and the response-payload explorer (#441), so
 * every outcome — including the resolution failures the validator renders as
 * assertion messages — must be a loud structured result here.
 */
class ResponseSchemaResolverTest extends TestCase
{
    private ResponseSchemaResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        OpenApiSpecLoader::reset();
        OpenApiSpecLoader::configure(__DIR__ . '/../../../fixtures/specs');
        $this->resolver = new ResponseSchemaResolver();
    }

    protected function tearDown(): void
    {
        OpenApiSpecLoader::reset();
        DiscriminatorEnforcement::reset();
        parent::tearDown();
    }

    #[Test]
    public function resolve_returns_converted_schema_and_metadata_for_exact_status_key(): void
    {
        $resolution = $this->resolver->resolve('petstore-3.0', 'GET', '/v1/pets/1', 200, 'application/json');

        $this->assertSame(ResponseSchemaResolutionOutcome::Resolved, $resolution->outcome);
        $this->assertSame('/v1/pets/{petId}', $resolution->matchedPath);
        $this->assertSame('200', $resolution->statusKey);
        $this->assertSame('application/json', $resolution->contentType);
        $this->assertNull($resolution->message);
        $this->assertNull($resolution->skipReason);

        // The raw schema node is the spec author's OAS 3.0 shape …
        $this->assertIsArray($resolution->schema);
        $this->assertTrue($resolution->schema['properties']['data']['properties']['tag']['nullable']);
        $this->assertArrayNotHasKey('$schema', $resolution->schema);

        // … while the converted schema went through the Draft 07 pipeline:
        // `nullable` lowered to a type array, dialect stamped.
        $converted = $resolution->convertedSchema();
        $this->assertSame(OpenApiSchemaDialect::DRAFT_07, $converted['$schema']);
        $tag = $converted['properties']['data']['properties']['tag'];
        $this->assertSame(['string', 'null'], $tag['type']);
        $this->assertArrayNotHasKey('nullable', $tag);
    }

    #[Test]
    public function resolve_matches_range_status_key(): void
    {
        $resolution = $this->resolver->resolve('range-keys', 'GET', '/widgets', 503, 'application/json');

        $this->assertSame(ResponseSchemaResolutionOutcome::Resolved, $resolution->outcome);
        $this->assertSame('5XX', $resolution->statusKey);
        $this->assertSame('application/json', $resolution->contentType);
    }

    #[Test]
    public function resolve_falls_back_to_default_status_key(): void
    {
        $resolution = $this->resolver->resolve('range-keys', 'GET', '/widgets-default', 404, 'application/json');

        $this->assertSame(ResponseSchemaResolutionOutcome::Resolved, $resolution->outcome);
        $this->assertSame('default', $resolution->statusKey);
    }

    #[Test]
    public function resolve_reports_undeclared_status(): void
    {
        $resolution = $this->resolver->resolve('range-keys', 'GET', '/widgets', 404, 'application/json');

        $this->assertSame(ResponseSchemaResolutionOutcome::StatusNotDeclared, $resolution->outcome);
        $this->assertSame('/widgets', $resolution->matchedPath);
        $this->assertNull($resolution->statusKey);
        $this->assertSame(
            "Status code 404 not defined for GET /widgets in 'range-keys' spec.",
            $resolution->message,
        );
    }

    #[Test]
    public function resolve_reports_path_not_found(): void
    {
        $resolution = $this->resolver->resolve('petstore-3.0', 'GET', '/nope', 200, 'application/json');

        $this->assertSame(ResponseSchemaResolutionOutcome::PathNotFound, $resolution->outcome);
        $this->assertNull($resolution->matchedPath);
        $this->assertNull($resolution->statusKey);
        $this->assertStringContainsString(
            "No matching path found in 'petstore-3.0' spec for GET /nope",
            (string) $resolution->message,
        );
    }

    #[Test]
    public function resolve_reports_method_not_defined(): void
    {
        $resolution = $this->resolver->resolve('petstore-3.0', 'DELETE', '/v1/health', 200, 'application/json');

        $this->assertSame(ResponseSchemaResolutionOutcome::MethodNotDefined, $resolution->outcome);
        $this->assertSame('/v1/health', $resolution->matchedPath);
        $this->assertStringContainsString(
            "Method DELETE not defined for path /v1/health in 'petstore-3.0' spec.",
            (string) $resolution->message,
        );
    }

    #[Test]
    public function resolve_reports_malformed_paths_node(): void
    {
        $resolution = $this->resolver->resolve('malformed-paths', 'GET', '/things', 200, 'application/json');

        $this->assertSame(ResponseSchemaResolutionOutcome::MalformedSpec, $resolution->outcome);
        $this->assertNull($resolution->matchedPath);
        $this->assertStringContainsString("Malformed 'paths'", (string) $resolution->message);
        $this->assertStringContainsString('expected object, got string', (string) $resolution->message);
    }

    #[Test]
    public function resolve_reports_malformed_responses_map(): void
    {
        $resolution = $this->resolver->resolve('malformed', 'GET', '/scalar-responses-map', 200, 'application/json');

        $this->assertSame(ResponseSchemaResolutionOutcome::MalformedSpec, $resolution->outcome);
        $this->assertSame('/scalar-responses-map', $resolution->matchedPath);
        $this->assertStringContainsString(
            "Malformed 'paths[\"/scalar-responses-map\"].get.responses'",
            (string) $resolution->message,
        );
    }

    #[Test]
    public function resolve_reports_malformed_response_entry_keyed_off_matched_spec_key(): void
    {
        $resolution = $this->resolver->resolve('malformed-response', 'GET', '/things', 200, 'application/json');

        $this->assertSame(ResponseSchemaResolutionOutcome::MalformedResponse, $resolution->outcome);
        $this->assertSame('200', $resolution->statusKey);
        $this->assertStringContainsString("Malformed 'responses[200]'", (string) $resolution->message);
        $this->assertStringContainsString('expected object, got string', (string) $resolution->message);

        // A wire status resolved through `default` must key the message off
        // the matched spec key, not the literal status (issue #258).
        $viaDefault = $this->resolver->resolve('malformed', 'GET', '/response-default-status-scalar', 200, 'application/json');
        $this->assertSame(ResponseSchemaResolutionOutcome::MalformedResponse, $viaDefault->outcome);
        $this->assertSame('default', $viaDefault->statusKey);
        $this->assertStringContainsString("Malformed 'responses[default]'", (string) $viaDefault->message);
    }

    #[Test]
    public function resolve_reports_malformed_content_nodes(): void
    {
        $contentBlock = $this->resolver->resolve('malformed', 'GET', '/response-scalar-content', 200, 'application/json');
        $this->assertSame(ResponseSchemaResolutionOutcome::MalformedContent, $contentBlock->outcome);
        $this->assertStringContainsString("Malformed 'responses[200].content'", (string) $contentBlock->message);

        $mediaType = $this->resolver->resolve('malformed', 'GET', '/response-scalar-content-media-type', 200, 'application/json');
        $this->assertSame(ResponseSchemaResolutionOutcome::MalformedContent, $mediaType->outcome);
        $this->assertStringContainsString(
            'Malformed \'responses[200].content["application/json"]\'',
            (string) $mediaType->message,
        );

        $schema = $this->resolver->resolve('malformed', 'GET', '/response-scalar-content-schema', 200, 'application/json');
        $this->assertSame(ResponseSchemaResolutionOutcome::MalformedContent, $schema->outcome);
        $this->assertStringContainsString(
            'Malformed \'responses[200].content["application/json"].schema\'',
            (string) $schema->message,
        );

        $nullSchema = $this->resolver->resolve('malformed', 'GET', '/response-null-content-schema', 200, 'application/json');
        $this->assertSame(ResponseSchemaResolutionOutcome::MalformedContent, $nullSchema->outcome);
        $this->assertStringContainsString(
            'Malformed \'responses[200].content["application/json"].schema\'',
            (string) $nullSchema->message,
        );
    }

    #[Test]
    public function resolve_reports_no_content_for_content_less_response(): void
    {
        $resolution = $this->resolver->resolve('petstore-3.0', 'DELETE', '/v1/pets/1', 204);

        $this->assertSame(ResponseSchemaResolutionOutcome::NoContent, $resolution->outcome);
        $this->assertSame('/v1/pets/{petId}', $resolution->matchedPath);
        $this->assertSame('204', $resolution->statusKey);
        $this->assertNull($resolution->contentType);
        $this->assertIsArray($resolution->responseSpec);
    }

    #[Test]
    public function resolve_reports_no_json_content(): void
    {
        $resolution = $this->resolver->resolve('non-json-content-schema', 'GET', '/text-without-schema', 200);

        $this->assertSame(ResponseSchemaResolutionOutcome::NoJsonContent, $resolution->outcome);
        $this->assertSame('200', $resolution->statusKey);
        $this->assertNull($resolution->contentType);
    }

    #[Test]
    public function resolve_reports_missing_schema_for_matched_non_json_type(): void
    {
        $resolution = $this->resolver->resolve('non-json-content-schema', 'GET', '/text-without-schema', 200, 'text/plain');

        $this->assertSame(ResponseSchemaResolutionOutcome::MissingSchema, $resolution->outcome);
        $this->assertSame('text/plain', $resolution->contentType);
    }

    #[Test]
    public function resolve_reports_missing_schema_for_json_key_without_schema(): void
    {
        $resolution = $this->resolver->resolve('content-without-schema', 'GET', '/widgets', 201, 'application/json');

        $this->assertSame(ResponseSchemaResolutionOutcome::MissingSchema, $resolution->outcome);
        $this->assertSame('application/json', $resolution->contentType);
    }

    #[Test]
    public function resolve_reports_unvalidatable_non_json_schema(): void
    {
        // The charset parameter exercises the same normalization the
        // validator's body path always applied before matching.
        $resolution = $this->resolver->resolve('non-json-content-schema', 'GET', '/text-with-schema', 200, 'text/plain; charset=utf-8');

        $this->assertSame(ResponseSchemaResolutionOutcome::NonJsonSchema, $resolution->outcome);
        $this->assertSame('text/plain', $resolution->contentType);
        $this->assertStringContainsString('cannot evaluate', (string) $resolution->skipReason);
        $this->assertStringContainsString('JSON Schema engine only', (string) $resolution->skipReason);
    }

    #[Test]
    public function resolve_reports_unvalidatable_schema_matched_via_wildcard_range(): void
    {
        // Issue #254 skip detection keys off `findContentTypeKey()`, which
        // also matches `<type>/*` ranges — not just exact keys.
        $resolution = $this->resolver->resolve('response-negotiation-edge', 'GET', '/wildcard-schema', 200, 'application/octet-stream');

        $this->assertSame(ResponseSchemaResolutionOutcome::NonJsonSchema, $resolution->outcome);
        $this->assertSame('application/*', $resolution->contentType);
        $this->assertNotNull($resolution->skipReason);
    }

    #[Test]
    public function resolve_preserves_spec_content_type_casing(): void
    {
        // The spec author wrote a mixed-case media type — the matched key
        // keeps that casing so coverage reports show it verbatim.
        $resolution = $this->resolver->resolve('response-negotiation-edge', 'GET', '/casing', 200, 'application/problem+json');

        $this->assertSame(ResponseSchemaResolutionOutcome::Resolved, $resolution->outcome);
        $this->assertSame('Application/Problem+JSON', $resolution->contentType);
    }

    #[Test]
    public function resolve_reports_list_shaped_media_type_nodes(): void
    {
        // JSON lists pass `is_array()` but are not objects; the shared
        // MalformedSpecNode guard flags them like scalars (issue #256).
        $entry = $this->resolver->resolve('response-negotiation-edge', 'GET', '/list-media-entry', 200, 'application/json');
        $this->assertSame(ResponseSchemaResolutionOutcome::MalformedContent, $entry->outcome);
        $this->assertStringContainsString('expected object, got list', (string) $entry->message);

        $schema = $this->resolver->resolve('response-negotiation-edge', 'GET', '/list-media-schema', 200, 'application/json');
        $this->assertSame(ResponseSchemaResolutionOutcome::MalformedContent, $schema->outcome);
        $this->assertStringContainsString(
            'Malformed \'responses[200].content["application/json"].schema\'',
            (string) $schema->message,
        );
    }

    #[Test]
    public function resolve_flags_null_schema_on_non_json_media_type(): void
    {
        // Before the guard, a non-JSON entry with `schema: null` slipped
        // through the `isset(...['schema'])` skip check (issue #254) as a
        // silent success. The guard runs before content negotiation, so the
        // node is rejected loudly regardless of the actual Content-Type.
        $resolution = $this->resolver->resolve('response-negotiation-edge', 'GET', '/null-schema-non-json', 200, 'text/plain');

        $this->assertSame(ResponseSchemaResolutionOutcome::MalformedContent, $resolution->outcome);
        $this->assertStringContainsString(
            'Malformed \'responses[200].content["text/plain"].schema\'',
            (string) $resolution->message,
        );
        $this->assertNull($resolution->skipReason);
    }

    #[Test]
    public function resolve_flags_malformed_entry_even_when_not_the_negotiated_content_type(): void
    {
        // The guard loop pre-scans every media-type entry before content
        // negotiation runs: a malformed `text/plain` entry is flagged even
        // though the JSON Content-Type would negotiate the well-formed
        // `application/json` entry.
        $resolution = $this->resolver->resolve('response-negotiation-edge', 'GET', '/guard-order', 200, 'application/json');

        $this->assertSame(ResponseSchemaResolutionOutcome::MalformedContent, $resolution->outcome);
        $this->assertStringContainsString(
            'Malformed \'responses[200].content["text/plain"]\'',
            (string) $resolution->message,
        );
    }

    #[Test]
    public function resolve_reports_undeclared_content_type(): void
    {
        $resolution = $this->resolver->resolve('petstore-3.0', 'GET', '/v1/pets/1', 200, 'text/html');

        $this->assertSame(ResponseSchemaResolutionOutcome::ContentTypeNotDeclared, $resolution->outcome);
        $this->assertNull($resolution->contentType);
        $this->assertSame(
            "Response Content-Type 'text/html' is not defined for GET /v1/pets/{petId} (status 200) "
            . "in 'petstore-3.0' spec. Defined content types: application/json",
            $resolution->message,
        );
    }

    #[Test]
    public function resolve_reports_item_schema_streaming(): void
    {
        $withContentType = $this->resolver->resolve('openapi-3.2', 'GET', '/v1/events', 200, 'text/event-stream');
        $this->assertSame(ResponseSchemaResolutionOutcome::ItemSchemaStreaming, $withContentType->outcome);
        $this->assertSame('text/event-stream', $withContentType->contentType);
        $this->assertStringContainsString('itemSchema streaming', (string) $withContentType->skipReason);

        // Without an actual Content-Type, the streaming media type is still
        // detected by the no-JSON-key scan and stays a loud outcome.
        $withoutContentType = $this->resolver->resolve('openapi-3.2', 'GET', '/v1/events', 200);
        $this->assertSame(ResponseSchemaResolutionOutcome::ItemSchemaStreaming, $withoutContentType->outcome);
        $this->assertSame('text/event-stream', $withoutContentType->contentType);
    }

    #[Test]
    public function resolve_prefers_exact_json_content_type_match(): void
    {
        $resolution = $this->resolver->resolve('petstore-3.1', 'GET', '/v1/v31/multi-content', 200, 'application/problem+json');

        $this->assertSame(ResponseSchemaResolutionOutcome::Resolved, $resolution->outcome);
        $this->assertSame('application/problem+json', $resolution->contentType);
        $this->assertSame(['title'], $resolution->schema['required'] ?? null);
    }

    #[Test]
    public function resolve_falls_back_to_first_json_content_type(): void
    {
        $resolution = $this->resolver->resolve('petstore-3.1', 'GET', '/v1/v31/multi-content', 200);

        $this->assertSame(ResponseSchemaResolutionOutcome::Resolved, $resolution->outcome);
        $this->assertSame('application/json', $resolution->contentType);

        // 3.1 documents convert through their declared dialect, not Draft 07.
        $this->assertSame(OpenApiSchemaDialect::DRAFT_2020_12, $resolution->convertedSchema()['$schema']);
    }

    #[Test]
    public function resolve_preserves_discriminator_enforcement(): void
    {
        $enforced = $this->resolver->resolve('openapi-3.2', 'GET', '/v1/pets/1', 200, 'application/json')->convertedSchema();

        DiscriminatorEnforcement::configure(false);
        $stripped = $this->resolver->resolve('openapi-3.2', 'GET', '/v1/pets/1', 200, 'application/json')->convertedSchema();

        // Both pipelines consume the raw `discriminator` keyword, but only the
        // enforcing conversion lowers the mapping into extra constraints — if
        // the resolver dropped the discriminator context, the two would agree.
        $this->assertArrayNotHasKey('discriminator', $enforced);
        $this->assertArrayNotHasKey('discriminator', $stripped);
        $this->assertNotSame($enforced, $stripped);
    }

    #[Test]
    public function staged_resolution_matches_composed_resolve(): void
    {
        $operation = $this->resolver->resolveOperation('petstore-3.0', 'GET', '/v1/pets/1');

        $this->assertSame(ResponseSchemaResolutionOutcome::Resolved, $operation->outcome);
        $this->assertSame('/v1/pets/{petId}', $operation->matchedPath);
        $this->assertSame(OpenApiVersion::V3_0, $operation->version);
        $this->assertSame(OpenApiSchemaDialect::DRAFT_07, $operation->jsonSchemaDialect);
        $this->assertIsArray($operation->responses);
        $this->assertArrayHasKey('200', $operation->responses);

        $staged = $this->resolver->resolveResponseSchema($operation, 200, 'application/json');
        $composed = $this->resolver->resolve('petstore-3.0', 'GET', '/v1/pets/1', 200, 'application/json');

        $this->assertSame($composed->outcome, $staged->outcome);
        $this->assertSame($composed->matchedPath, $staged->matchedPath);
        $this->assertSame($composed->statusKey, $staged->statusKey);
        $this->assertSame($composed->contentType, $staged->contentType);
        $this->assertSame($composed->schema, $staged->schema);
    }

    #[Test]
    public function resolve_response_schema_rejects_unresolved_operation(): void
    {
        $operation = $this->resolver->resolveOperation('petstore-3.0', 'GET', '/nope');
        $this->assertSame(ResponseSchemaResolutionOutcome::PathNotFound, $operation->outcome);

        $this->expectException(LogicException::class);
        $this->resolver->resolveResponseSchema($operation, 200, 'application/json');
    }

    #[Test]
    public function composed_resolve_maps_operation_failures_into_schema_resolution(): void
    {
        $resolution = $this->resolver->resolve('petstore-3.0', 'DELETE', '/v1/health', 200);

        $this->assertSame(ResponseSchemaResolutionOutcome::MethodNotDefined, $resolution->outcome);
        $this->assertSame('/v1/health', $resolution->matchedPath);
        $this->assertNull($resolution->statusKey);
        $this->assertNull($resolution->contentType);
        $this->assertNotNull($resolution->message);
    }

    #[Test]
    public function converted_schema_is_only_available_on_resolved_outcomes(): void
    {
        $resolution = $this->resolver->resolve('range-keys', 'GET', '/widgets', 404, 'application/json');
        $this->assertSame(ResponseSchemaResolutionOutcome::StatusNotDeclared, $resolution->outcome);

        $this->expectException(LogicException::class);
        $resolution->convertedSchema();
    }
}
