<?php

namespace Tests\Feature;

use Tests\TestCase;

final class InvalidTest extends TestCase
{
    public function test_rejects_bad_payload(): void
    {
        $this->postJson('/v1/users', [])
            ->assertInvalidRequest()
            ->assertValidResponse(422);
    }

    public function test_legacy_response(): void
    {
        $this->withHeaders(['Accept' => 'application/json'])
            ->getJson('/v1/legacy')
            ->assertInvalidResponse(200);
    }

    public function test_both_sides(): void
    {
        $response = $this->getJson('/v1/broken')->assertInvalidRequest()->assertInvalidResponse();
    }

    public function test_on_a_variable(): void
    {
        $response = $this->postJson('/v1/users', []);

        $response->assertInvalidRequest();
    }
}

?>
-----
<?php

namespace Tests\Feature;

use Tests\TestCase;

final class InvalidTest extends TestCase
{
    use \Studio\Gesso\Laravel\ValidatesOpenApiSchema;
    public function test_rejects_bad_payload(): void
    {
        $this->expectInvalidRequest()->postJson('/v1/users', [])
            ->assertStatus(422);
    }

    public function test_legacy_response(): void
    {
        $this->expectInvalidResponse()->withHeaders(['Accept' => 'application/json'])
            ->getJson('/v1/legacy')
            ->assertStatus(200);
    }

    public function test_both_sides(): void
    {
        $response = $this->expectInvalidRequest()->expectInvalidResponse()->getJson('/v1/broken');
    }

    public function test_on_a_variable(): void
    {
        $response = $this->postJson('/v1/users', []);

        $response->assertInvalidRequest();
    }
}

?>
