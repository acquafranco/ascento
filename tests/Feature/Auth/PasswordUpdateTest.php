<?php

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class PasswordUpdateTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    public function test_password_can_be_updated(): void
    {
        $a = $this->makeTenant();
        $profile = "/{$a['company']->slug}/profile";

        $this->actingAs($a['technician'])
            ->from($profile)
            ->put('/password', [
                'current_password' => 'password',
                'password' => 'new-password',
                'password_confirmation' => 'new-password',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect($profile);

        $this->assertTrue(Hash::check('new-password', $a['technician']->refresh()->password));
    }

    public function test_correct_password_must_be_provided_to_update_password(): void
    {
        $a = $this->makeTenant();
        $profile = "/{$a['company']->slug}/profile";

        $this->actingAs($a['technician'])
            ->from($profile)
            ->put('/password', [
                'current_password' => 'wrong-password',
                'password' => 'new-password',
                'password_confirmation' => 'new-password',
            ])
            ->assertSessionHasErrorsIn('updatePassword', 'current_password')
            ->assertRedirect($profile);
    }
}
