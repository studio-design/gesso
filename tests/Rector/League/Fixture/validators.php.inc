<?php

namespace Tests;

use League\OpenAPIValidation\PSR7\Exception\ValidationFailed;
use League\OpenAPIValidation\PSR7\OperationAddress;
use League\OpenAPIValidation\PSR7\RequestValidator;
use League\OpenAPIValidation\PSR7\ValidatorBuilder;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class ApiTest extends TestCase
{
    private RequestValidator $requestValidator;

    public function test_exchange(ServerRequestInterface $request, ResponseInterface $response): void
    {
        $builder = (new ValidatorBuilder())->fromYamlFile('openapi.yaml');
        $builder->getServerRequestValidator()->validate($request);
        $this->requestValidator->validate($request);
        $builder->getRoutedRequestValidator()->validate(new OperationAddress('/users/{id}', 'get'), $request);
        $builder->getResponseValidator()->validate(new OperationAddress('/users/{id}', 'get'), $response);
        $this->expectException(ValidationFailed::class);
    }

    public function test_left_alone(ServerRequestInterface $request, ResponseInterface $response): void
    {
        $builder = (new ValidatorBuilder())->fromYamlFile('openapi.yaml');
        $address = $builder->getServerRequestValidator()->validate($request);
        $builder->getResponseValidator()->validate($address, $response);
        $this->expectException(\RuntimeException::class);
    }
}

?>
-----
<?php

namespace Tests;

use League\OpenAPIValidation\PSR7\Exception\ValidationFailed;
use League\OpenAPIValidation\PSR7\OperationAddress;
use League\OpenAPIValidation\PSR7\RequestValidator;
use League\OpenAPIValidation\PSR7\ValidatorBuilder;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class ApiTest extends TestCase
{
    use \Studio\Gesso\Psr7\OpenApiAssertions;
    private RequestValidator $requestValidator;

    public function test_exchange(ServerRequestInterface $request, ResponseInterface $response): void
    {
        $builder = (new ValidatorBuilder())->fromYamlFile('openapi.yaml');
        $this->assertOpenApiRequest($request);
        $this->assertOpenApiRequest($request);
        $this->assertOpenApiRequest($request);
        $this->assertOpenApiResponse($response, method: 'get', path: '/users/{id}');
        $this->expectException(\PHPUnit\Framework\AssertionFailedError::class);
    }

    public function test_left_alone(ServerRequestInterface $request, ResponseInterface $response): void
    {
        $builder = (new ValidatorBuilder())->fromYamlFile('openapi.yaml');
        $address = $builder->getServerRequestValidator()->validate($request);
        $builder->getResponseValidator()->validate($address, $response);
        $this->expectException(\RuntimeException::class);
    }
}

?>
