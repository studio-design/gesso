<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Tests\Unit\Validation\Support;

use const E_USER_WARNING;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\OpenApiContractTesting\Validation\Support\SpecResponseKeyResolver;

use function restore_error_handler;
use function set_error_handler;

class SpecResponseKeyResolverTest extends TestCase
{
    #[Test]
    public function resolve_returns_exact_match_when_present(): void
    {
        $responses = ['200' => [], '5XX' => [], 'default' => []];
        $key = SpecResponseKeyResolver::resolve('200', $responses);

        $this->assertSame('200', $key);
    }

    #[Test]
    public function resolve_prefers_exact_over_range_and_default(): void
    {
        // Spec has both `503` (literal) and `5XX` (range). Exact wins.
        $responses = ['503' => [], '5XX' => [], 'default' => []];
        $key = SpecResponseKeyResolver::resolve('503', $responses);

        $this->assertSame('503', $key);
    }

    #[Test]
    public function resolve_uppercase_range_key_matches(): void
    {
        $responses = ['200' => [], '5XX' => []];
        $key = SpecResponseKeyResolver::resolve('503', $responses);

        $this->assertSame('5XX', $key);
    }

    #[Test]
    public function resolve_lowercase_range_key_matches(): void
    {
        // OpenAPI examples use uppercase but lowercase is a tolerated convention.
        $responses = ['200' => [], '5xx' => []];
        $key = SpecResponseKeyResolver::resolve('503', $responses);

        $this->assertSame('5xx', $key);
    }

    #[Test]
    public function resolve_rejects_mixed_case_range_key(): void
    {
        // `5Xx` is neither uppercase XX nor lowercase xx — explicitly rejected
        // so spec-author typos that look like range keys can't silently match.
        $responses = ['200' => [], '5Xx' => []];
        $key = SpecResponseKeyResolver::resolve('503', $responses);

        $this->assertNull($key);
    }

    #[Test]
    public function resolve_falls_back_to_default(): void
    {
        $responses = ['200' => [], 'default' => []];
        $key = SpecResponseKeyResolver::resolve('418', $responses);

        $this->assertSame('default', $key);
    }

    #[Test]
    public function resolve_returns_null_when_nothing_matches(): void
    {
        $responses = ['200' => [], '500' => []];
        $key = SpecResponseKeyResolver::resolve('418', $responses);

        $this->assertNull($key);
    }

    #[Test]
    public function resolve_returns_null_for_empty_responses(): void
    {
        $this->assertNull(SpecResponseKeyResolver::resolve('200', []));
    }

    #[Test]
    public function resolve_handles_numeric_string_keys_after_php_coercion(): void
    {
        // PHP coerces numeric string keys ("200") to int (200) when used as
        // array keys. The resolver casts back to string before regex tests so
        // the matched key is still a string in the return value.
        $responses = ['200' => [], '404' => []];
        $key = SpecResponseKeyResolver::resolve('404', $responses);

        $this->assertSame('404', $key);
    }

    #[Test]
    public function warn_suspicious_keys_emits_warning_for_typo_alongside_default(): void
    {
        $captured = [];
        set_error_handler(static function (int $errno, string $errstr) use (&$captured): bool {
            if ($errno === E_USER_WARNING) {
                $captured[] = $errstr;

                return true;
            }

            return false;
        });

        try {
            /** @var array<string, mixed> $responses */
            $responses = ['200' => [], '40' => [], 'default' => []];
            SpecResponseKeyResolver::warnSuspiciousKeys('fixture', 'GET', '/widgets', $responses);
        } finally {
            restore_error_handler();
        }

        $this->assertCount(1, $captured, 'one warning per non-conforming key');
        $this->assertStringContainsString("'40'", $captured[0]);
        $this->assertStringContainsString("falling back to 'default'", $captured[0]);
    }

    #[Test]
    public function warn_suspicious_keys_skips_valid_status_range_and_default_keys(): void
    {
        $captured = [];
        set_error_handler(static function (int $errno) use (&$captured): bool {
            $captured[] = $errno;

            return $errno === E_USER_WARNING;
        });

        try {
            /** @var array<string, mixed> $responses */
            $responses = ['200' => [], '5XX' => [], '4xx' => [], 'default' => []];
            SpecResponseKeyResolver::warnSuspiciousKeys('fixture', 'GET', '/widgets', $responses);
        } finally {
            restore_error_handler();
        }

        $this->assertSame([], $captured, 'no warnings for valid keys');
    }
}
