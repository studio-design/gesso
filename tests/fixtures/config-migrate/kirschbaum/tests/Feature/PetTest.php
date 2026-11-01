<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

final class PetTest extends TestCase
{
    public function test_missing_pet(): void
    {
        $this->skipResponseCode(404);
        $this->get('/pets/1')->assertNotFound();
        $this->get('/pets/2')->assertNotFound();
    }
}
