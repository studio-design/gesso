<?php

declare(strict_types=1);

namespace Examples\Psr7\Tests;

use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\OpenApiContractTesting\Attribute\OpenApiSpec;
use Studio\OpenApiContractTesting\Psr7\OpenApiAssertions;

#[OpenApiSpec('petstore')]
final class PetContractTest extends TestCase
{
    use OpenApiAssertions;

    #[Test]
    public function validates_a_psr7_exchange(): void
    {
        $request = new Request(
            'POST',
            'https://example.test/pets',
            ['Content-Type' => 'application/json'],
            '{"name":"Fido"}',
        );
        $response = new Response(
            201,
            ['Content-Type' => 'application/json'],
            '{"id":1,"name":"Fido"}',
        );

        $this->assertPsr7ExchangeMatchesOpenApiSchema($request, $response);
    }
}
