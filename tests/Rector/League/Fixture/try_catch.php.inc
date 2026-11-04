<?php

namespace Tests;

use League\OpenAPIValidation\PSR7\Exception\ValidationFailed;
use League\OpenAPIValidation\PSR7\OperationAddress;
use League\OpenAPIValidation\PSR7\ValidatorBuilder;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

final class ResponseTest extends TestCase
{
    public function test_response_matches(ResponseInterface $response): void
    {
        $validator = (new ValidatorBuilder())->fromYamlFile('openapi.yaml');

        try {
            $validator->getResponseValidator()->validate(new OperationAddress('/users', 'get'), $response);
        } catch (ValidationFailed $e) {
            $this->fail($e->getMessage());
        }

        if (true) {
            try {
                $validator->getResponseValidator()->validate(new OperationAddress('/users', 'get'), $response);
            } catch (ValidationFailed $e) {
                self::fail($e->getMessage());
            }
        }

        try {
            $validator->getResponseValidator()->validate(new OperationAddress('/users', 'get'), $response);
            $this->send();
        } catch (ValidationFailed $e) {
            $this->fail($e->getMessage());
        }

        try {
            $validator->getResponseValidator()->validate(new OperationAddress('/users', 'get'), $response);
        } catch (ValidationFailed $e) {
            $this->markTestSkipped();
        }
    }
}

?>
-----
<?php

namespace Tests;

use League\OpenAPIValidation\PSR7\Exception\ValidationFailed;
use League\OpenAPIValidation\PSR7\OperationAddress;
use League\OpenAPIValidation\PSR7\ValidatorBuilder;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

final class ResponseTest extends TestCase
{
    use \Studio\Gesso\Psr7\OpenApiAssertions;
    public function test_response_matches(ResponseInterface $response): void
    {
        $validator = (new ValidatorBuilder())->fromYamlFile('openapi.yaml');

        $this->assertOpenApiResponse($response, method: 'get', path: '/users');

        if (true) {
            $this->assertOpenApiResponse($response, method: 'get', path: '/users');
        }

        try {
            $this->assertOpenApiResponse($response, method: 'get', path: '/users');
            $this->send();
        } catch (ValidationFailed $e) {
            $this->fail($e->getMessage());
        }

        try {
            $this->assertOpenApiResponse($response, method: 'get', path: '/users');
        } catch (ValidationFailed $e) {
            $this->markTestSkipped();
        }
    }
}

?>
