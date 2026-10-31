<?php

declare(strict_types=1);

namespace Tests;

use Osteel\OpenApi\Testing\ValidatorBuilder;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ApiTest extends WebTestCase
{
    public function test_lists_users(): void
    {
        $client = self::createClient();
        $client->request('GET', '/users');

        $validator = ValidatorBuilder::fromYamlFile('openapi/api.yaml')->getValidator();

        $this->assertTrue($validator->validate($client->getResponse(), '/users', 'get'));
    }
}
