<?php

namespace Tests\Feature\Security;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ErrorPagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_error_pages_use_the_ascento_brand_without_technical_details(): void
    {
        $this->get('/esta-pagina-no-existe/ni-esta')->assertNotFound()
            ->assertSee('No encontramos esta página')
            ->assertSee('images/brand/logo-128.png', false)
            ->assertDontSee('Laravel');
    }
}
