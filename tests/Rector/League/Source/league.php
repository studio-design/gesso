<?php

declare(strict_types=1);

// The parts of league/openapi-psr7-validator the fixtures need, required once
// per test by the Rector test case.

namespace League\OpenAPIValidation\PSR7;

use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

if (!\class_exists(ValidatorBuilder::class, false)) {
    final class OperationAddress
    {
        public function __construct(public string $path, public string $method) {}
    }

    final class ServerRequestValidator
    {
        public function validate(ServerRequestInterface $serverRequest): OperationAddress
        {
            return new OperationAddress('/', 'get');
        }
    }

    final class RequestValidator
    {
        public function validate(RequestInterface $request): OperationAddress
        {
            return new OperationAddress('/', 'get');
        }
    }

    final class RoutedServerRequestValidator
    {
        public function validate(OperationAddress $opAddr, ServerRequestInterface $serverRequest): void {}
    }

    final class ResponseValidator
    {
        public function validate(OperationAddress $opAddr, ResponseInterface $response): void {}
    }

    final class ValidatorBuilder
    {
        public function fromYamlFile(string $yamlFile): self
        {
            return $this;
        }

        public function getServerRequestValidator(): ServerRequestValidator
        {
            return new ServerRequestValidator();
        }

        public function getRequestValidator(): RequestValidator
        {
            return new RequestValidator();
        }

        public function getRoutedRequestValidator(): RoutedServerRequestValidator
        {
            return new RoutedServerRequestValidator();
        }

        public function getResponseValidator(): ResponseValidator
        {
            return new ResponseValidator();
        }
    }
}

namespace League\OpenAPIValidation\PSR7\Exception;

if (!\class_exists(ValidationFailed::class, false)) {
    class ValidationFailed extends \RuntimeException {}
}
