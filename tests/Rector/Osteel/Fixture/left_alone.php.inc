<?php

namespace Tests\Feature;

use Osteel\OpenApi\Testing\ValidatorInterface;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\HttpFoundation\Response;

final class MixedTest extends TestCase
{
    private ValidatorInterface $validator;

    public function test_both(Response $response, ResponseInterface $psr): void
    {
        $this->validator->get($response, '/users');
        $this->validator->get($psr, '/users');
    }
}

final class UntypedTest extends TestCase
{
    public function test_untyped($validator, $response, Response $typed): void
    {
        $validator->get($typed, '/users');
        $this->validator()->get($response, '/users');
    }
}

abstract class LaravelBase extends TestCase
{
    use \Studio\Gesso\Laravel\ValidatesOpenApiSchema;
}

final class LaravelTest extends LaravelBase
{
    private ValidatorInterface $validator;

    public function test_users(Response $response): void
    {
        $this->validator->get($response, '/users');
    }
}

?>
