<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Integration;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\Gesso\Coverage\OpenApiCoverageTracker;
use Studio\Gesso\OpenApiResponseValidator;
use Studio\Gesso\OpenApiValidationResult;
use Studio\Gesso\Spec\OpenApiSpecLoader;
use Studio\Gesso\Validation\Strict\StrictRequiredTracker;

/**
 * Issue #773: a `$ref` resolves against the base URI its enclosing `$id` sets,
 * and a resource embedded in the document is found in place, not fetched.
 */
final class IdScopedRefsTest extends TestCase
{
    private const array PET = ['name' => 'Rex', 'owner' => ['name' => 7], 'tags' => ['good']];

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

    /** @return iterable<string, array{string, mixed}> */
    public static function provideValid_bodies_passCases(): iterable
    {
        yield 'reference by $id URI' => ['/tags', ['a', 'b']];
        yield 'same resource twice in one body' => ['/pets', ['first' => self::PET, 'second' => self::PET]];
        yield 'relative $id against the document location' => ['/widgets', ['id' => 1]];
    }

    /** @return iterable<string, array{string, mixed, string}> */
    public static function provideInvalid_bodies_failCases(): iterable
    {
        yield 'reference by $id URI' => ['/tags', [1], '/0'];
        yield '#/$defs under an $id resolves in that resource' => [
            '/pets',
            ['first' => [...self::PET, 'name' => 'far too long a name']],
            '/first/name',
        ];
        yield 'nested resource reads its own $defs' => [
            '/pets',
            ['second' => [...self::PET, 'owner' => ['name' => 'Ada']]],
            '/second/owner/name',
        ];
        yield '#/components under an $id falls back to the document' => [
            '/pets',
            ['first' => [...self::PET, 'tags' => [1]]],
            '/first/tags/0',
        ];
        yield 'relative $id against the document location' => ['/widgets', [], 'id'];
    }

    #[Test]
    #[DataProvider('provideValid_bodies_passCases')]
    public function valid_bodies_pass(string $path, mixed $body): void
    {
        $result = $this->validate($path, $body);

        $this->assertSame([], $result->errors());
    }

    #[Test]
    #[DataProvider('provideInvalid_bodies_failCases')]
    public function invalid_bodies_fail(string $path, mixed $body, string $location): void
    {
        $result = $this->validate($path, $body);

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString($location, $result->errorMessage());
    }

    private function validate(string $path, mixed $body): OpenApiValidationResult
    {
        return (new OpenApiResponseValidator(strictRequiredTracker: new StrictRequiredTracker()))->validate(
            'id-scoped-refs-3.1',
            'GET',
            $path,
            200,
            $body,
            'application/json',
        );
    }
}
