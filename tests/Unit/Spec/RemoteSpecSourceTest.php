<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit\Spec;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\Gesso\Spec\RemoteSpecSource;

use function str_repeat;

final class RemoteSpecSourceTest extends TestCase
{
    #[Test]
    public function constructs_with_url_only(): void
    {
        $source = new RemoteSpecSource('https://specs.example.com/openapi.json');

        $this->assertSame('https://specs.example.com/openapi.json', $source->url);
        $this->assertNull($source->authorizationEnv);
        $this->assertNull($source->expectedSha256);
    }

    #[Test]
    public function rejects_non_http_url(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('http:// or https://');

        new RemoteSpecSource('file:///etc/openapi.json');
    }

    #[Test]
    public function rejects_relative_url(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('http:// or https://');

        new RemoteSpecSource('openapi/front.json');
    }

    #[Test]
    public function rejects_url_without_host(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('host');

        new RemoteSpecSource('https:///openapi.json');
    }

    #[Test]
    public function rejects_empty_authorization_env_name(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('authorizationEnv');

        new RemoteSpecSource('https://specs.example.com/openapi.json', authorizationEnv: '  ');
    }

    #[Test]
    public function rejects_malformed_expected_sha256(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('expectedSha256');

        new RemoteSpecSource('https://specs.example.com/openapi.json', expectedSha256: 'abc123');
    }

    #[Test]
    public function normalizes_expected_sha256_to_lowercase(): void
    {
        $digest = str_repeat('AB', 32);
        $source = new RemoteSpecSource('https://specs.example.com/openapi.json', expectedSha256: $digest);

        $this->assertSame(str_repeat('ab', 32), $source->expectedSha256);
    }
}
