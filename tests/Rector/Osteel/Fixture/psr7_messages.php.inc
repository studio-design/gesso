<?php

namespace Tests\Feature;

use Osteel\OpenApi\Testing\ValidatorBuilder;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class Psr7Test extends TestCase
{
    public function test_users(ServerRequestInterface $request, ResponseInterface $response): void
    {
        $validator = ValidatorBuilder::fromYamlFile('openapi.yaml')->getValidator();
        $validator->get($request, '/users');
        $validator->get($response, '/users');
    }
}

?>
-----
<?php

namespace Tests\Feature;

use Osteel\OpenApi\Testing\ValidatorBuilder;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class Psr7Test extends TestCase
{
    use \Studio\Gesso\Psr7\OpenApiAssertions;
    public function test_users(ServerRequestInterface $request, ResponseInterface $response): void
    {
        $validator = ValidatorBuilder::fromYamlFile('openapi.yaml')->getValidator();
        $this->assertOpenApiRequest($request);
        $this->assertOpenApiResponse($response, method: 'GET', path: '/users');
    }
}

?>
