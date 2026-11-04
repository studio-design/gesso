<?php

namespace Tests;

use Osteel\OpenApi\Testing\ValidatorBuilder;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;

final class ApiTest extends TestCase
{
    public function test_lists_users(Response $response): void
    {
        $validator = ValidatorBuilder::fromYamlFile('openapi/api.yaml')->getValidator();

        $this->assertTrue($validator->validate($response, '/users', 'get'));
    }
}

?>
-----
<?php

namespace Tests;

use Osteel\OpenApi\Testing\ValidatorBuilder;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;

final class ApiTest extends TestCase
{
    use \Studio\Gesso\Symfony\OpenApiAssertions;
    public function test_lists_users(Response $response): void
    {
        $validator = ValidatorBuilder::fromYamlFile('openapi/api.yaml')->getValidator();

        $this->assertOpenApiResponse($response, method: 'get', path: '/users');
    }
}

?>
