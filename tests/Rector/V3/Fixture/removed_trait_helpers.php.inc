<?php

namespace App\Tests;

use Studio\Gesso\Laravel\ExploresOpenApiEndpoint;
use Studio\Gesso\Laravel\ValidatesOpenApiSchema;
use Studio\Gesso\Psr7\OpenApiAssertions;

final class LaravelTest
{
    use ValidatesOpenApiSchema;
    use ExploresOpenApiEndpoint;

    public function test(): void
    {
        $this->assertOpenApi($condition, 'message');
        if (!$this->shouldSkipOpenApi()) {
            $this->failOpenApi('message');
        }
        $this->failExplore('message');
        $this->failPsr7('not composed by these traits');
        $helper = new class {
            public function run(): void
            {
                $this->failOpenApi('another class');
            }
        };
    }
}

final class Psr7Test
{
    use OpenApiAssertions;

    public function test(): void
    {
        $this->failPsr7('message');
        $this->assertPsr7($condition, 'message');
        $this->shouldSkipOpenApi();
    }
}

final class OwnHelperTest
{
    use ValidatesOpenApiSchema;

    public function test(): void
    {
        $this->failOpenApi('my own method');
    }

    private function failOpenApi(string $message): never
    {
        throw new \LogicException($message);
    }
}

final class NoTraitTest
{
    public function test(): void
    {
        $this->failOpenApi('message');
    }
}

?>
-----
<?php

namespace App\Tests;

use Studio\Gesso\Laravel\ExploresOpenApiEndpoint;
use Studio\Gesso\Laravel\ValidatesOpenApiSchema;
use Studio\Gesso\Psr7\OpenApiAssertions;

final class LaravelTest
{
    use ValidatesOpenApiSchema;
    use ExploresOpenApiEndpoint;

    public function test(): void
    {
        $this->assertTrue($condition, 'message');
        if (!($this->findSkipOpenApiAttribute() !== null)) {
            $this->fail('message');
        }
        $this->fail('message');
        $this->failPsr7('not composed by these traits');
        $helper = new class {
            public function run(): void
            {
                $this->failOpenApi('another class');
            }
        };
    }
}

final class Psr7Test
{
    use OpenApiAssertions;

    public function test(): void
    {
        $this->fail('message');
        $this->assertTrue($condition, 'message');
        $this->shouldSkipOpenApi();
    }
}

final class OwnHelperTest
{
    use ValidatesOpenApiSchema;

    public function test(): void
    {
        $this->failOpenApi('my own method');
    }

    private function failOpenApi(string $message): never
    {
        throw new \LogicException($message);
    }
}

final class NoTraitTest
{
    public function test(): void
    {
        $this->failOpenApi('message');
    }
}

?>
