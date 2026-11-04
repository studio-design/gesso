<?php

namespace Tests\Feature;

use Osteel\OpenApi\Testing\Exceptions\ValidationException;
use Osteel\OpenApi\Testing\ValidatorInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class UsersTest extends TestCase
{
    private ValidatorInterface $validator;

    public function test_users(Request $request, Response $response): void
    {
        $this->validator->validate($response, '/users/{id}', 'get');
        $this->validator->post($request, '/users');
        $this->assertTrue($this->validator->patch($response, '/users/{id}'));
        self::assertTrue($this->validator->delete($response, '/users/{id}'), 'deleted');
        $valid = $this->validator->get($response, '/users');
        $this->expectException(ValidationException::class);
        $this->post('/users', []);
    }
}

?>
-----
<?php

namespace Tests\Feature;

use Osteel\OpenApi\Testing\Exceptions\ValidationException;
use Osteel\OpenApi\Testing\ValidatorInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class UsersTest extends TestCase
{
    use \Studio\Gesso\Symfony\OpenApiAssertions;
    private ValidatorInterface $validator;

    public function test_users(Request $request, Response $response): void
    {
        $this->assertOpenApiResponse($response, method: 'get', path: '/users/{id}');
        $this->assertOpenApiRequest($request);
        $this->assertOpenApiResponse($response, method: 'PATCH', path: '/users/{id}');
        $this->assertOpenApiResponse($response, method: 'DELETE', path: '/users/{id}');
        $valid = $this->validator->get($response, '/users');
        $this->expectException(\PHPUnit\Framework\AssertionFailedError::class);
        $this->post('/users', []);
    }
}

?>
