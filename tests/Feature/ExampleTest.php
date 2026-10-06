<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_pages_render(): void
    {
        foreach (['/', '/login', '/register', '/forgot-password', '/legal/terminos', '/legal/privacidad', '/legal/eliminacion-de-datos'] as $page) {
            $this->get($page)->assertOk();
        }
    }
}
