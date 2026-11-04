<?php

namespace Tests;

use League\OpenAPIValidation\PSR7\ServerRequestValidator;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;

final class LaravelTest extends TestCase
{
    use \Studio\Gesso\Laravel\ValidatesOpenApiSchema;

    private ServerRequestValidator $validator;

    public function test_request(ServerRequestInterface $request): void
    {
        $this->validator->validate($request);
    }
}

?>
