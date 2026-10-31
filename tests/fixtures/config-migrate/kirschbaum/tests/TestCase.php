<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Kirschbaum\OpenApiValidator\ValidatesOpenApiSpec;

abstract class TestCase extends BaseTestCase
{
    use ValidatesOpenApiSpec;
    protected $responseCodesToSkip = ['5\d\d', 404];
}
