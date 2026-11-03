<?php

namespace Tests;

use Osteel\OpenApi\Testing\ValidatorBuilder;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\HttpKernelBrowser;

final class ApiTest extends TestCase
{
    public function test_lists_users(HttpKernelBrowser $client): void
    {
        $client->request('GET', '/users');

        $validator = ValidatorBuilder::fromYamlFile('openapi/api.yaml')->getValidator();

        $this->assertTrue($validator->validate($client->getResponse(), '/users', 'get'));
    }
}

?>
-----
<?php

namespace Tests;

use Osteel\OpenApi\Testing\ValidatorBuilder;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\HttpKernelBrowser;

final class ApiTest extends TestCase
{
    use \Studio\Gesso\Symfony\OpenApiAssertions;
    public function test_lists_users(HttpKernelBrowser $client): void
    {
        $client->request('GET', '/users');

        $validator = ValidatorBuilder::fromYamlFile('openapi/api.yaml')->getValidator();

        $this->assertOpenApiResponse($client->getResponse(), method: 'get', path: '/users');
    }
}

?>
