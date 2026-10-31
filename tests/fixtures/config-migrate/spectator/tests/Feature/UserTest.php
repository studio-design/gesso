<?php

declare(strict_types=1);

namespace Tests\Feature;

use Spectator\Spectator;
use Tests\TestCase;

final class UserTest extends TestCase
{
    public function test_lists_users(): void
    {
        Spectator::using('api.yaml');

        $this->getJson('/v1/users')
            ->assertValidRequest()
            ->assertValidResponse(200);
    }

    public function test_rejects_bad_payload(): void
    {
        Spectator::using('api.yaml');

        $this->postJson('/v1/users', [])
            ->assertInvalidRequest()
            ->assertValidResponse();
    }
}
