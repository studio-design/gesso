<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Integration;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\Gesso\Coverage\OpenApiCoverageTracker;
use Studio\Gesso\OpenApiRequestValidator;
use Studio\Gesso\OpenApiResponseValidator;
use Studio\Gesso\OpenApiValidationResult;
use Studio\Gesso\Spec\OpenApiSpecLoader;
use Studio\Gesso\Validation\Strict\StrictRequiredTracker;

/**
 * Issue #772: `id: {allOf: [{$ref: Id}]}` is the OpenAPI 3.0 way to describe a
 * reference, so a `readOnly` / `writeOnly` marker on the target must still be
 * enforced. `oneOf` / `anyOf` mark the property only when every branch does.
 */
final class ReadWriteOnlyThroughCombinersTest extends TestCase
{
    private const array VALID_REQUEST = ['label' => 'x', 'name' => 'Ada', 'password' => 'hunter2'];
    private const array VALID_RESPONSE = ['id' => 1, 'parentId' => 2, 'kind' => 'admin', 'label' => 3, 'name' => 'Ada'];

    protected function setUp(): void
    {
        parent::setUp();
        OpenApiSpecLoader::reset();
        OpenApiCoverageTracker::reset();
        OpenApiSpecLoader::configure(__DIR__ . '/../fixtures/specs');
    }

    protected function tearDown(): void
    {
        OpenApiCoverageTracker::reset();
        OpenApiSpecLoader::reset();
        parent::tearDown();
    }

    /** @return iterable<string, array{string, string, mixed}> */
    public static function provideRequest_rejects_a_read_only_propertyCases(): iterable
    {
        foreach (['readwrite-allof-3.0', 'readwrite-allof-3.1'] as $spec) {
            yield "{$spec} allOf wrapper" => [$spec, 'id', 1];
            yield "{$spec} nested allOf" => [$spec, 'parentId', 1];
            yield "{$spec} oneOf, every branch read-only" => [$spec, 'kind', 'admin'];
        }
    }

    /** @return iterable<string, array{string}> */
    public static function provideSpecCases(): iterable
    {
        yield '3.0' => ['readwrite-allof-3.0'];
        yield '3.1' => ['readwrite-allof-3.1'];
    }

    #[Test]
    #[DataProvider('provideRequest_rejects_a_read_only_propertyCases')]
    public function request_rejects_a_read_only_property(string $spec, string $property, mixed $value): void
    {
        $result = (new OpenApiRequestValidator())->validate(
            $spec,
            'POST',
            '/users',
            [],
            [],
            [...self::VALID_REQUEST, $property => $value],
            'application/json',
        );

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString($property, $result->errorMessage());
    }

    #[Test]
    #[DataProvider('provideSpecCases')]
    public function request_omitting_required_read_only_properties_passes(string $spec): void
    {
        // `label` is anyOf [read-only Id, string]: one unmarked branch keeps it writable.
        $result = (new OpenApiRequestValidator())->validate($spec, 'POST', '/users', [], [], self::VALID_REQUEST, 'application/json');

        $this->assertTrue($result->isValid(), $result->errorMessage());
    }

    #[Test]
    #[DataProvider('provideSpecCases')]
    public function response_rejects_a_write_only_property(string $spec): void
    {
        $result = self::validateResponse($spec, [...self::VALID_RESPONSE, 'password' => 'leaked']);

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('password', $result->errorMessage());
    }

    #[Test]
    #[DataProvider('provideSpecCases')]
    public function response_omitting_required_write_only_property_passes(string $spec): void
    {
        $result = self::validateResponse($spec, self::VALID_RESPONSE);

        $this->assertTrue($result->isValid(), $result->errorMessage());
    }

    /** @param array<string, mixed> $body */
    private static function validateResponse(string $spec, array $body): OpenApiValidationResult
    {
        return (new OpenApiResponseValidator(new StrictRequiredTracker()))->validate($spec, 'POST', '/users', 201, $body);
    }
}
