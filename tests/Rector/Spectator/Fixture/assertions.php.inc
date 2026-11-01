<?php

namespace Tests\Feature;

use Spectator\Spectator;
use Tests\TestCase;

final class UserTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Spectator::using('api.yaml');
    }

    public function test_lists_users(): void
    {
        $this->getJson('/v1/users')
            ->assertValidRequest()
            ->assertValidResponse(200)
            ->assertJsonCount(2);
    }

    public function test_creates_a_user(): void
    {
        $response = $this->postJson('/v1/users', ['name' => 'Ada']);

        $response->assertValidRequest()->assertValidResponse();
        $response->assertValidResponse(201);
    }

    public function test_deletes_a_user(): void
    {
        $this->deleteJson('/v1/users/1')->assertValidResponse();
        Spectator::reset();
    }

    public function test_rejects_bad_payload(): void
    {
        $this->postJson('/v1/users', [])
            ->assertInvalidRequest()
            ->assertValidResponse();
    }

    public function test_typed_response(\Illuminate\Testing\TestResponse $response): void
    {
        $response->assertValidResponse(200)->assertValidRequest();
    }

    public function test_nested_class(): void
    {
        $double = new class {
            public function assertValidResponse(): void {}
        };
        $double->assertValidResponse();
    }
}

?>
-----
<?php

namespace Tests\Feature;

use Spectator\Spectator;
use Tests\TestCase;

final class UserTest extends TestCase
{
    use \Studio\Gesso\Laravel\ValidatesOpenApiSchema;
    protected function setUp(): void
    {
        parent::setUp();
    }

    public function test_lists_users(): void
    {
        $this->getJson('/v1/users')
            ->assertStatus(200)
            ->assertJsonCount(2);
    }

    public function test_creates_a_user(): void
    {
        $response = $this->postJson('/v1/users', ['name' => 'Ada']);
        $response->assertStatus(201);
    }

    public function test_deletes_a_user(): void
    {
        $this->deleteJson('/v1/users/1');
    }

    public function test_rejects_bad_payload(): void
    {
        $this->postJson('/v1/users', [])
            ->assertInvalidRequest();
    }

    public function test_typed_response(\Illuminate\Testing\TestResponse $response): void
    {
        $response->assertStatus(200);
    }

    public function test_nested_class(): void
    {
        $double = new class {
            public function assertValidResponse(): void {}
        };
        $double->assertValidResponse();
    }
}

?>
