<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit\Fuzz;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\Gesso\Fuzz\ResponseStatusExtractor;

class ResponseStatusExtractorTest extends TestCase
{
    #[Test]
    public function accepts_a_plain_int(): void
    {
        $this->assertSame(405, ResponseStatusExtractor::extract(405));
    }

    #[Test]
    public function accepts_an_object_with_get_status_code(): void
    {
        $response = new class {
            public function getStatusCode(): int
            {
                return 204;
            }
        };

        $this->assertSame(204, ResponseStatusExtractor::extract($response));
    }

    #[Test]
    public function accepts_a_magic_call_proxy(): void
    {
        $response = new class {
            /** @param list<mixed> $arguments */
            public function __call(string $name, array $arguments): int
            {
                return $name === 'getStatusCode' ? 418 : 0;
            }
        };

        $this->assertSame(418, ResponseStatusExtractor::extract($response));
    }

    #[Test]
    public function rejects_unsupported_return_values(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/int status code, a PSR-7 response, or an object exposing getStatusCode\(\)/');
        ResponseStatusExtractor::extract(['status' => 200]);
    }

    #[Test]
    public function rejects_a_non_int_status_from_the_object(): void
    {
        $response = new class {
            public function getStatusCode(): string
            {
                return 'ok';
            }
        };

        $this->expectException(InvalidArgumentException::class);
        ResponseStatusExtractor::extract($response);
    }
}
