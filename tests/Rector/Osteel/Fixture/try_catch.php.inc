<?php

namespace Tests;

use Osteel\OpenApi\Testing\Exceptions\ValidationException;
use Osteel\OpenApi\Testing\ValidatorInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;

final class TryTest extends TestCase
{
    private ValidatorInterface $validator;

    public function test_users(Response $response): void
    {
        try {
            $this->validator->get($response, '/users');
        } catch (ValidationException $e) {
            $this->fail($e->getMessage());
        }
    }
}

?>
-----
<?php

namespace Tests;

use Osteel\OpenApi\Testing\Exceptions\ValidationException;
use Osteel\OpenApi\Testing\ValidatorInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;

final class TryTest extends TestCase
{
    use \Studio\Gesso\Symfony\OpenApiAssertions;
    private ValidatorInterface $validator;

    public function test_users(Response $response): void
    {
        $this->assertOpenApiResponse($response, method: 'GET', path: '/users');
    }
}

?>
