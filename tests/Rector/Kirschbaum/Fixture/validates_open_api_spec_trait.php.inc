<?php

namespace Tests\Feature;

use Kirschbaum\OpenApiValidator\ValidatesOpenApiSpec;
use Tests\TestCase;

final class UsersTest extends TestCase
{
    use ValidatesOpenApiSpec;

    public function test_list(): void
    {
        $this->withoutRequestValidation()->getJson('/users')->assertOk();
        $this->skipResponseCode(404)->getJson('/users/0');
    }
}

final class FullyQualifiedTest extends TestCase
{
    use \Kirschbaum\OpenApiValidator\ValidatesOpenApiSpec;
}

?>
-----
<?php

namespace Tests\Feature;

use Kirschbaum\OpenApiValidator\ValidatesOpenApiSpec;
use Tests\TestCase;

final class UsersTest extends TestCase
{
    use \Studio\Gesso\Laravel\ValidatesOpenApiSchema;

    public function test_list(): void
    {
        $this->withoutRequestValidation()->getJson('/users')->assertOk();
        $this->skipResponseCode(404)->getJson('/users/0');
    }
}

final class FullyQualifiedTest extends TestCase
{
    use \Studio\Gesso\Laravel\ValidatesOpenApiSchema;
}

?>
