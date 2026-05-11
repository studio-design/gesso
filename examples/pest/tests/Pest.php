<?php

declare(strict_types=1);

use Examples\Pest\Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Pest configuration
|--------------------------------------------------------------------------
|
| Wires the Pest tests under tests/Feature to the Orchestra Testbench
| harness in TestCase.php so each `it(...)` block inherits Laravel routes,
| the spec loader configuration, and the ValidatesOpenApiSchema trait.
|
| In a real Laravel project this typically looks like:
|
|     use Tests\TestCase;
|     use Studio\OpenApiContractTesting\Laravel\ValidatesOpenApiSchema;
|
|     uses(TestCase::class, ValidatesOpenApiSchema::class)->in('Feature');
|
| The example flattens the trait into TestCase directly because the
| harness is also where routes / package providers are defined.
*/

uses(TestCase::class)->in('Feature');
