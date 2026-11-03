<?php

declare(strict_types=1);

namespace Examples\Symfony\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\Gesso\Attribute\OpenApiSpec;
use Studio\Gesso\Symfony\OpenApiAssertions;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

#[OpenApiSpec('petstore')]
final class PetContractTest extends TestCase
{
    use OpenApiAssertions;

    #[Test]
    public function validates_http_foundation_request_and_response(): void
    {
        $request = Request::create('/pets', 'GET');
        $response = new Response('[{"id":1,"name":"Fido"}]', 200, [
            'Content-Type' => 'application/json',
        ]);

        $this->assertOpenApiRequest($request, 200);
        $this->assertOpenApiResponse($response, $request);
    }
}
