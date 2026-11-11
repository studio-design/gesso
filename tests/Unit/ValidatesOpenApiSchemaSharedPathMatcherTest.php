<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit;

use const JSON_THROW_ON_ERROR;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Studio\Gesso\Coverage\OpenApiCoverageTracker;
use Studio\Gesso\HttpMethod;
use Studio\Gesso\Laravel\ValidatesOpenApiSchema;
use Studio\Gesso\Spec\OpenApiPathMatcher;
use Studio\Gesso\Spec\OpenApiSpecLoader;
use Studio\Gesso\Tests\Helpers\CreatesTestResponse;
use Symfony\Component\HttpFoundation\Request;

use function array_values;
use function json_encode;

require_once __DIR__ . '/../Helpers/LaravelConfigMock.php';

/**
 * Validators live per test-case instance, so the compiled path matcher has to
 * outlive them: every hook of every test case validating against one spec
 * shares a single compiled matcher (issue #720).
 */
class ValidatesOpenApiSchemaSharedPathMatcherTest extends TestCase
{
    use CreatesTestResponse;
    use ValidatesOpenApiSchema;

    protected function setUp(): void
    {
        parent::setUp();
        OpenApiSpecLoader::reset();
        OpenApiSpecLoader::configure(__DIR__ . '/../fixtures/specs');
        OpenApiCoverageTracker::reset();
        $GLOBALS['__openapi_testing_config'] = [
            'gesso.spec.default' => 'petstore-3.0',
            'gesso.laravel.auto_assert' => true,
            'gesso.laravel.auto_validate_request' => true,
            'gesso.laravel.auto_inject_dummy_credentials' => true,
        ];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['__openapi_testing_config']);
        OpenApiSpecLoader::reset();
        OpenApiCoverageTracker::reset();
        parent::tearDown();
    }

    #[Test]
    public function two_test_case_instances_share_one_compiled_matcher(): void
    {
        // Injection off, so only the request and response validators can
        // have compiled the matcher.
        $GLOBALS['__openapi_testing_config']['gesso.laravel.auto_inject_dummy_credentials'] = false;

        $this->exerciseAllHooks();
        $afterFirst = self::sharedMatchers();
        $this->assertCount(1, $afterFirst);

        (new self($this->name()))->exerciseAllHooks();

        $this->assertSame($afterFirst, self::sharedMatchers());
    }

    #[Test]
    public function credential_injection_shares_the_validators_matcher(): void
    {
        $spec = OpenApiSpecLoader::load('petstore-3.0');
        $this->findOperationForRequest($spec['paths'], 'GET', '/v1/pets', OpenApiSpecLoader::serverBasePaths('petstore-3.0'));
        $afterLookup = self::sharedMatchers();
        $this->assertCount(1, $afterLookup);

        // The validators must arrive at the same key, not a second compile.
        $this->exerciseAllHooks();

        $this->assertSame($afterLookup, self::sharedMatchers());
    }

    #[Test]
    public function changed_strip_prefixes_get_their_own_matcher(): void
    {
        $this->exerciseAllHooks();
        $unprefixed = self::sharedMatchers();

        OpenApiSpecLoader::setStripPrefixes(['/api']);
        OpenApiCoverageTracker::reset();
        (new self($this->name()))->exerciseAllHooks('/api');

        $matchers = self::sharedMatchers();
        $this->assertCount(2, $matchers);
        $this->assertSame($unprefixed[0], $matchers[0]);
        $this->assertNotSame($unprefixed[0], $matchers[1]);
        // The new matcher is the one in effect: the prefixed paths resolved.
        $this->assertArrayHasKey('GET /v1/pets', OpenApiCoverageTracker::getCovered()['petstore-3.0'] ?? []);
    }

    /** @return list<OpenApiPathMatcher> */
    private static function sharedMatchers(): array
    {
        /** @var array<string, OpenApiPathMatcher> $shared */
        $shared = (new ReflectionProperty(OpenApiPathMatcher::class, 'shared'))->getValue();

        return array_values($shared);
    }

    private function exerciseAllHooks(string $prefix = ''): void
    {
        $path = $prefix . '/v1/pets';
        $this->maybeAutoValidateOpenApiRequest(Request::create($path, 'GET'), HttpMethod::GET, $path);

        $body = (string) json_encode(['data' => [['id' => 1, 'name' => 'Fido', 'tag' => null]]], JSON_THROW_ON_ERROR);
        $this->maybeAutoAssertOpenApiSchema($this->makeTestResponse($body, 200), HttpMethod::GET, $path);
    }
}
