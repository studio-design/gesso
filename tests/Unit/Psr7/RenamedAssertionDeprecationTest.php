<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit\Psr7;

use const E_USER_DEPRECATED;

use Closure;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\Gesso\Coverage\OpenApiCoverageTracker;
use Studio\Gesso\Internal\Deprecations;
use Studio\Gesso\Psr7\OpenApiAssertions;
use Studio\Gesso\Spec\OpenApiSpecLoader;

use function restore_error_handler;
use function set_error_handler;

/**
 * The v2 assertion names forward to the v3 names, which 2.10 already has, and
 * notice once per call.
 */
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
     * @return iterable<string, array{string, Closure(self, Request, Response): void}>
     */
    public static function provideAn_old_name_notices_and_still_validatesCases(): iterable
    {
        yield 'assertPsr7RequestMatchesOpenApiSchema' => ['assert_psr7_request_matches_open_api_schema', static fn(self $t, Request $q) => $t->assertPsr7RequestMatchesOpenApiSchema($q)];
        yield 'assertPsr7ResponseMatchesOpenApiSchema' => ['assert_psr7_response_matches_open_api_schema', static fn(self $t, Request $q, Response $r) => $t->assertPsr7ResponseMatchesOpenApiSchema($q, $r)];
        yield 'assertPsr7ResponseForOperationMatchesOpenApiSchema' => ['assert_psr7_response_for_operation_matches_open_api_schema', static fn(self $t, Request $q, Response $r) => $t->assertPsr7ResponseForOperationMatchesOpenApiSchema('POST', '/widgets/42', $r)];
        yield 'assertPsr7ExchangeMatchesOpenApiSchema' => ['assert_psr7_exchange_matches_open_api_schema', static fn(self $t, Request $q, Response $r) => $t->assertPsr7ExchangeMatchesOpenApiSchema($q, $r)];
    }

    /**
     * @param Closure(self, Request, Response): void $call
     */
    #[Test]
    #[DataProvider('provideAn_old_name_notices_and_still_validatesCases')]
    public function an_old_name_notices_and_still_validates(string $id, Closure $call): void
    {
        $request = new Request('POST', '/widgets/42', ['Content-Type' => 'application/json'], '{"name":42}');
        $response = new Response(201, ['Content-Type' => 'application/json'], '{"id":"not-an-int"}');

        try {
            $call($this, $request, $response);
            $this->fail('expected the contract assertion to fail');
        } catch (AssertionFailedError $e) {
            $this->assertStringContainsString('OpenAPI PSR-7', $e->getMessage());
        }

        $this->assertSame(['trait.psr7_open_api_assertions.' . $id => 1], Deprecations::counts());
        $this->assertCount(1, $this->captured);
    }

    protected function openApiSpec(): string
    {
        return 'psr7';
    }
}
