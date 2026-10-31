<?php

declare(strict_types=1);

namespace Tests;

use League\OpenAPIValidation\PSR7\Exception\ValidationFailed;
use League\OpenAPIValidation\PSR7\OperationAddress;
use League\OpenAPIValidation\PSR7\ValidatorBuilder;
use PHPUnit\Framework\TestCase;

final class ResponseTest extends TestCase
{
    public function test_response_matches(): void
    {
        $validator = (new ValidatorBuilder())->fromJsonFile('openapi/front.json');
        $response = $this->send();

        try {
            $validator->getResponseValidator()->validate(new OperationAddress('/users', 'get'), $response);
        } catch (ValidationFailed $e) {
            $this->fail($e->getMessage());
        }
    }
}
