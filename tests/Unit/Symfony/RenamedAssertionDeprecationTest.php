<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit\Symfony;

use const E_USER_DEPRECATED;

use Closure;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\Gesso\Attribute\OpenApiSpec;
use Studio\Gesso\Coverage\OpenApiCoverageTracker;
use Studio\Gesso\Internal\Deprecations;
use Studio\Gesso\Spec\OpenApiSpecLoader;
use Studio\Gesso\Symfony\OpenApiAssertions;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelBrowser;
use Symfony\Component\HttpKernel\HttpKernelInterface;

use function restore_error_handler;
use function set_error_handler;

/**
 * The v2 assertion names forward to the v3 names, which 2.10 already has, and
 * notice once per call.
 */
#[OpenApiSpec('petstore-3.0')]
final class RenamedAssertionDeprecationTest extends TestCase
{
    use OpenApiAssertions;

    /** @var list<string> */
    private array $captured = [];

    protected function setUp(): void
    {
        parent::setUp();
        Deprecations::resetForTesting();
        OpenApiSpecLoader::reset();
        OpenApiCoverageTracker::reset();
        OpenApiSpecLoader::configure(__DIR__ . '/../../fixtures/specs');
        $this->captured = [];
        set_error_handler(function (int $errno, string $message): bool {
            $this->captured[] = $message;

            return true;
        }, E_USER_DEPRECATED);
    }

    protected function tearDown(): void
    {
        restore_error_handler();
        Deprecations::resetForTesting();
        OpenApiSpecLoader::reset();
        OpenApiCoverageTracker::reset();
        parent::tearDown();
    }

    /**
     * @return iterable<string, array{string, Closure(self): void}>
     */
    public static function provideAn_old_name_notices_and_still_validatesCases(): iterable
    {
        yield 'assertResponseMatchesOpenApiSchema' => ['assert_response_matches_open_api_schema', static fn(self $t) => $t->assertResponseMatchesOpenApiSchema(Request::create('/v1/pets', 'GET'), new JsonResponse(['wrong_key' => 'value']))];
        yield 'assertRequestMatchesOpenApiSchema' => ['assert_request_matches_open_api_schema', static fn(self $t) => $t->assertRequestMatchesOpenApiSchema(Request::create('/v1/pets', 'POST', server: ['CONTENT_TYPE' => 'application/json'], content: '{}'))];
        yield 'assertClientMatchesOpenApiSchema' => ['assert_client_matches_open_api_schema', static function (self $t): void {
            $client = new HttpKernelBrowser(new class implements HttpKernelInterface {
                public function handle(Request $request, int $type = self::MAIN_REQUEST, bool $catch = true): Response
                {
                    return new JsonResponse(['wrong_key' => 'value']);
                }
            });
            $client->request('GET', '/v1/pets');
            $t->assertClientMatchesOpenApiSchema($client);
        }];
    }

    /**
     * @param Closure(self): void $call
     */
    #[Test]
    #[DataProvider('provideAn_old_name_notices_and_still_validatesCases')]
    public function an_old_name_notices_and_still_validates(string $id, Closure $call): void
    {
        try {
            $call($this);
            $this->fail('expected the contract assertion to fail');
        } catch (AssertionFailedError $e) {
            $this->assertStringContainsString('validation failed for', $e->getMessage());
        }

        $this->assertSame(['trait.symfony_open_api_assertions.' . $id => 1], Deprecations::counts());
        $this->assertCount(1, $this->captured);
    }
}
