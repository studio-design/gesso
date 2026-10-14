<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit\Internal;

use Exception;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Studio\Gesso\Internal\StackTraceFilter;

use function array_column;
use function array_keys;
use function dirname;
use function str_replace;

class StackTraceFilterTest extends TestCase
{
    #[Test]
    public function drops_frames_from_the_actual_source_root_regardless_of_checkout_name(): void
    {
        $trace = [
            ['file' => self::src() . '/Laravel/ValidatesOpenApiSchema.php', 'line' => 500],
            ['file' => '/app/tests/Feature/PetTest.php', 'line' => 47],
        ];

        $filtered = StackTraceFilter::filterFrames($trace);

        $this->assertSame(['/app/tests/Feature/PetTest.php'], array_column($filtered, 'file'));
    }

    #[Test]
    public function drops_makes_http_requests_frames(): void
    {
        $trace = [
            ['file' => '/app/vendor/laravel/framework/src/Illuminate/Foundation/Testing/Concerns/MakesHttpRequests.php', 'line' => 617],
            ['file' => '/app/vendor/laravel/framework/src/Illuminate/Foundation/Testing/Concerns/MakesHttpRequests.php', 'line' => 573],
            ['file' => '/app/tests/Feature/PetTest.php', 'line' => 47],
        ];

        $filtered = StackTraceFilter::filterFrames($trace);

        $this->assertSame(['/app/tests/Feature/PetTest.php'], array_column($filtered, 'file'));
    }

    #[Test]
    public function drops_test_response_frames(): void
    {
        $trace = [
            ['file' => '/app/vendor/laravel/framework/src/Illuminate/Testing/TestResponse.php', 'line' => 100],
            ['file' => '/app/tests/Feature/PetTest.php', 'line' => 47],
        ];

        $filtered = StackTraceFilter::filterFrames($trace);

        $this->assertSame(['/app/tests/Feature/PetTest.php'], array_column($filtered, 'file'));
    }

    #[Test]
    public function preserves_user_frames(): void
    {
        $trace = [
            ['file' => '/app/tests/Feature/Helpers/MyApiHelper.php', 'line' => 10],
            ['file' => '/app/tests/Feature/PetTest.php', 'line' => 47],
        ];

        $filtered = StackTraceFilter::filterFrames($trace);

        $this->assertCount(2, $filtered);
        $this->assertSame('/app/tests/Feature/Helpers/MyApiHelper.php', $filtered[0]['file']);
        $this->assertSame('/app/tests/Feature/PetTest.php', $filtered[1]['file']);
    }

    #[Test]
    public function preserves_order_of_remaining_frames(): void
    {
        $trace = [
            ['file' => self::src() . '/Laravel/ValidatesOpenApiSchema.php', 'line' => 500],
            ['file' => '/app/tests/Feature/A.php', 'line' => 1],
            ['file' => '/app/vendor/laravel/framework/src/Illuminate/Foundation/Testing/Concerns/MakesHttpRequests.php', 'line' => 617],
            ['file' => '/app/tests/Feature/B.php', 'line' => 2],
        ];

        $filtered = StackTraceFilter::filterFrames($trace);

        $this->assertSame(
            ['/app/tests/Feature/A.php', '/app/tests/Feature/B.php'],
            array_column($filtered, 'file'),
        );
        $this->assertSame([0, 1], array_keys($filtered));
    }

    #[Test]
    public function returns_identical_list_when_nothing_matches(): void
    {
        $trace = [
            ['file' => '/app/tests/Feature/A.php', 'line' => 1],
            ['file' => '/app/tests/Feature/B.php', 'line' => 2],
        ];

        $this->assertSame($trace, StackTraceFilter::filterFrames($trace));
    }

    #[Test]
    public function preserves_frames_without_a_file_key(): void
    {
        $trace = [
            ['function' => '{closure}'],
            ['file' => self::src() . '/Laravel/ValidatesOpenApiSchema.php', 'line' => 500],
            ['file' => '/app/tests/Feature/PetTest.php', 'line' => 47],
        ];

        $filtered = StackTraceFilter::filterFrames($trace);

        $this->assertCount(2, $filtered);
        $this->assertSame('{closure}', $filtered[0]['function']);
        $this->assertSame('/app/tests/Feature/PetTest.php', $filtered[1]['file']);
    }

    #[Test]
    public function normalizes_windows_style_backslash_paths(): void
    {
        $trace = [
            ['file' => str_replace('/', '\\', self::src()) . '\\Laravel\\ValidatesOpenApiSchema.php', 'line' => 500],
            ['file' => 'C:\\app\\tests\\Feature\\PetTest.php', 'line' => 47],
        ];

        $filtered = StackTraceFilter::filterFrames($trace);

        $this->assertCount(1, $filtered);
        $this->assertSame('C:\\app\\tests\\Feature\\PetTest.php', $filtered[0]['file']);
    }

    #[Test]
    public function rethrow_with_clean_trace_drops_library_frames_and_preserves_message(): void
    {
        $exception = new AssertionFailedError('boom');

        $traceProp = new ReflectionProperty(Exception::class, 'trace');
        $traceProp->setValue($exception, [
            ['file' => self::src() . '/Laravel/ValidatesOpenApiSchema.php', 'line' => 500],
            ['file' => '/app/tests/Feature/PetTest.php', 'line' => 47],
        ]);

        try {
            StackTraceFilter::rethrowWithCleanTrace($exception);
        } catch (AssertionFailedError $thrown) {
            $this->assertCount(1, $thrown->getTrace());
            $this->assertSame('/app/tests/Feature/PetTest.php', $thrown->getTrace()[0]['file']);
            // Message is unchanged — we only rewrite the trace.
            $this->assertSame('boom', $thrown->getMessage());
        }
    }

    #[Test]
    public function rethrow_preserves_original_trace_when_filtering_would_empty_it(): void
    {
        $exception = new AssertionFailedError('boom');

        $originalTrace = [
            ['file' => self::src() . '/Laravel/ValidatesOpenApiSchema.php', 'line' => 500],
            ['file' => '/app/vendor/laravel/framework/src/Illuminate/Foundation/Testing/Concerns/MakesHttpRequests.php', 'line' => 617],
        ];

        $traceProp = new ReflectionProperty(Exception::class, 'trace');
        $traceProp->setValue($exception, $originalTrace);

        try {
            StackTraceFilter::rethrowWithCleanTrace($exception);
        } catch (AssertionFailedError $thrown) {
            $this->assertSame($originalTrace, $thrown->getTrace());
        }
    }

    #[Test]
    public function rethrow_with_clean_trace_accepts_any_exception(): void
    {
        // Plain Exception, not AssertionFailedError: the filter is typed on
        // the reflection target so it carries no PHPUnit dependency (#506).
        $exception = new Exception('boom');

        $traceProp = new ReflectionProperty(Exception::class, 'trace');
        $traceProp->setValue($exception, [
            ['file' => self::src() . '/Fuzz/GeneratedResponseCase.php', 'line' => 101],
            ['file' => '/app/tests/Feature/PetTest.php', 'line' => 47],
        ]);

        try {
            StackTraceFilter::rethrowWithCleanTrace($exception);
        } catch (Exception $thrown) {
            $this->assertSame($exception, $thrown);
            $this->assertSame(['/app/tests/Feature/PetTest.php'], array_column($thrown->getTrace(), 'file'));
            $this->assertSame('boom', $thrown->getMessage());
        }
    }

    /** The real library source root, which StackTraceFilter drops by prefix. */
    private static function src(): string
    {
        return dirname(__DIR__, 3) . '/src';
    }
}
