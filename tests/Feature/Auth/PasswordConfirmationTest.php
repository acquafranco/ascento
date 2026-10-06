<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class PasswordConfirmationTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    public function test_confirm_password_screen_can_be_rendered(): void
    {
        $a = $this->makeTenant();

        $this->actingAs($a['technician'])->get('/confirm-password')->assertOk();
    }

    public function test_password_can_be_confirmed(): void
    {
        $a = $this->makeTenant();

        $this->actingAs($a['technician'])
            ->post('/confirm-password', ['password' => 'password'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();
    }

    public function test_super_admin_can_confirm_password_without_company(): void
    {
        // Antes: 500 ("Attempt to read property slug on null").
        $superAdmin = User::factory()->superAdmin()->create();

        $this->actingAs($superAdmin)
            ->post('/confirm-password', ['password' => 'password'])
            ->assertRedirect('/admin');
    }

    public function test_password_is_not_confirmed_with_invalid_password(): void
    {
        $a = $this->makeTenant();

        $this->actingAs($a['technician'])
            ->post('/confirm-password', ['password' => 'wrong-password'])
            ->assertSessionHasErrors();
    }
}
