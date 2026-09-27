<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Studio\Gesso\Laravel\ValidatesOpenApiSchema;
use Tests\TestCase;

final class ContractProbeTest extends TestCase
{
    use RefreshDatabase;
    use ValidatesOpenApiSchema;

    public function test_created_user_matches_generated_contract(): void
    {
        $response = $this->postJson('/api/users', [
            'name' => 'Contract Probe',
            'email' => 'probe@example.test',
        ]);
        $response->assertCreated();
        $this->assertResponseMatchesOpenApiSchema($response);
    }

    public function test_validation_error_matches_generated_contract(): void
    {
        $response = $this->postJson('/api/users', []);
        $response->assertUnprocessable();
        $this->assertResponseMatchesOpenApiSchema($response);
    }

    public function test_factory_backed_user_matches_generated_contract(): void
    {
        $user = User::factory()->create();
        $response = $this->getJson('/api/users/' . $user->id);
        $response->assertOk();
        $this->assertResponseMatchesOpenApiSchema($response);
    }

    public function test_authenticated_profile_matches_generated_contract(): void
    {
        $this->actingAs(User::factory()->create());
        $response = $this->getJson('/api/profile');
        $response->assertOk();
        $this->assertResponseMatchesOpenApiSchema($response);
    }

    public function test_unauthenticated_profile_matches_generated_contract(): void
    {
        $response = $this->getJson('/api/profile');
        $response->assertUnauthorized();
        $this->assertResponseMatchesOpenApiSchema($response);
    }

    public function test_missing_user_matches_generated_contract(): void
    {
        $response = $this->getJson('/api/users/999999');
        $response->assertNotFound();
        $this->assertResponseMatchesOpenApiSchema($response);
    }
}
