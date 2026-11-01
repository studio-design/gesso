<?php

namespace Tests\Feature;

use Studio\Gesso\Laravel\ValidatesOpenApiSchema;
use Tests\TestCase;

final class OrderTest extends TestCase
{
    use ValidatesOpenApiSchema;

    public function test_lists_orders(): void
    {
        $this->getJson('/v1/orders')->assertStatus(200);
    }
}

final class TraitAlreadyUsedTest extends TestCase
{
    use ValidatesOpenApiSchema;

    public function test_lists_orders(): void
    {
        $this->getJson('/v1/orders')->assertValidResponse(200);
    }
}

?>
-----
<?php

namespace Tests\Feature;

use Studio\Gesso\Laravel\ValidatesOpenApiSchema;
use Tests\TestCase;

final class OrderTest extends TestCase
{
    use ValidatesOpenApiSchema;

    public function test_lists_orders(): void
    {
        $this->getJson('/v1/orders')->assertStatus(200);
    }
}

final class TraitAlreadyUsedTest extends TestCase
{
    use ValidatesOpenApiSchema;

    public function test_lists_orders(): void
    {
        $this->getJson('/v1/orders')->assertStatus(200);
    }
}

?>
