<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    private function profileUrl(array $tenant): string
    {
        return "/{$tenant['company']->slug}/profile";
    }

    public function test_profile_page_is_displayed(): void
    {
        $a = $this->makeTenant();

        $this->actingAs($a['technician'])->get($this->profileUrl($a))->assertOk();
    }

    public function test_profile_information_can_be_updated(): void
    {
        $a = $this->makeTenant();
        $user = $a['technician'];

        $this->actingAs($user)
            ->patch($this->profileUrl($a), [
                'name' => 'Test User',
                'email' => 'test@example.com',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect($this->profileUrl($a));

        $user->refresh();

        $this->assertSame('Test User', $user->name);
        $this->assertSame('test@example.com', $user->email);
        $this->assertNull($user->email_verified_at);
    }

    public function test_profile_update_ignores_privilege_fields(): void
    {
        $a = $this->makeTenant();
        $b = $this->makeTenant();
        $user = $a['technician'];

        $this->actingAs($user)
            ->patch($this->profileUrl($a), [
                'name' => 'Técnico',
                'email' => $user->email,
                'role' => 'admin',
                'company_id' => $b['company']->id,
                'is_super_admin' => 1,
                'job_type' => 'admin',
            ])
            ->assertSessionHasNoErrors();

        $user->refresh();

        $this->assertSame('technician', $user->role);
        $this->assertSame($a['company']->id, $user->company_id);
        $this->assertFalse($user->isSuperAdmin());
        $this->assertSame('maintenance', $user->job_type);
    }

    public function test_email_must_be_unique_across_all_companies(): void
    {
        $a = $this->makeTenant();
        $b = $this->makeTenant();

        $this->actingAs($a['technician'])
            ->patch($this->profileUrl($a), ['name' => 'x', 'email' => $b['admin']->email])
            ->assertSessionHasErrors('email');
    }

    public function test_email_verification_status_is_unchanged_when_the_email_address_is_unchanged(): void
    {
        $a = $this->makeTenant();
        $user = $a['technician'];

        $this->actingAs($user)
            ->patch($this->profileUrl($a), ['name' => 'Test User', 'email' => $user->email])
            ->assertSessionHasNoErrors()
            ->assertRedirect($this->profileUrl($a));

        $this->assertNotNull($user->refresh()->email_verified_at);
    }

    public function test_technician_cannot_delete_their_own_account(): void
    {
        $a = $this->makeTenant();
        $user = $a['technician'];

        $this->actingAs($user)
            ->get($this->profileUrl($a))
            ->assertOk()
            ->assertSee('Baja de la cuenta')
            ->assertDontSee('confirm-user-deletion');

        $this->actingAs($user)
            ->delete($this->profileUrl($a), ['password' => 'password'])
            ->assertStatus(405);

        $this->assertNotNull($user->fresh());
        $this->assertNull($user->fresh()->deleted_at);
    }
}
