<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit\Psr7;

use const E_USER_DEPRECATED;

use Closure;
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
 * The private helpers composed into the public adapter traits are deprecated
 * for removal in 3.0 (#506). Gesso's own failures route around them, so the
 * notice must fire only when consumer code calls one.
 */
final class PrivateHelperDeprecationTest extends TestCase
{
    use OpenApiAssertions;

    /** @var list<array{int, string}> */
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
            $this->captured[] = [$errno, $message];

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
     * @return iterable<string, array{string, Closure(self): void, bool}>
     */
    public static function provideCalling_a_deprecated_helper_notices_and_still_behavesCases(): iterable
    {
        yield 'failOpenApi' => ['trait.open_api_assertions.fail_open_api', static fn(self $t) => $t->failOpenApi('boom'), true];
        yield 'assertOpenApi' => ['trait.open_api_assertions.assert_open_api', static fn(self $t) => $t->assertOpenApi(false, 'boom'), true];
        yield 'failPsr7' => ['trait.psr7_open_api_assertions.fail_psr7', static fn(self $t) => $t->failPsr7('boom'), true];
        yield 'assertPsr7' => ['trait.psr7_open_api_assertions.assert_psr7', static fn(self $t) => $t->assertPsr7(true, 'unused'), false];
    }

    /**
     * @param Closure(self): void $call
     */
    #[Test]
    #[DataProvider('provideCalling_a_deprecated_helper_notices_and_still_behavesCases')]
    public function calling_a_deprecated_helper_notices_and_still_behaves(string $id, Closure $call, bool $fails): void
    {
        $threw = false;

        try {
            $call($this);
        } catch (AssertionFailedError $e) {
            $threw = true;
            $this->assertStringStartsWith('boom', $e->getMessage());
        }

        $this->assertSame($fails, $threw);
        $this->assertSame([$id => 1], Deprecations::counts());
        $this->assertCount(1, $this->captured);
        $this->assertStringStartsWith(Deprecations::PREFIX, $this->captured[0][1]);
    }

    #[Test]
    public function gesso_own_failures_do_not_notice(): void
    {
        try {
            $this->assertPsr7ResponseForOperationMatchesOpenApiSchema(
                'POST',
                '/widgets/42',
                new Response(201, ['Content-Type' => 'application/json', 'X-Trace' => 'trace-1'], '{"id":"not-an-int"}'),
            );
            $this->fail('expected the contract assertion to fail');
        } catch (AssertionFailedError $e) {
            // A schema verdict, not an early exit (e.g. no spec configured).
            $this->assertStringContainsString('spec: psr7', $e->getMessage());
            $this->assertStringContainsString('/id', $e->getMessage());
        }

        $this->assertSame([], Deprecations::counts());
        $this->assertSame([], $this->captured);
    }

    protected function openApiSpec(): string
    {
        return 'psr7';
    }
}
