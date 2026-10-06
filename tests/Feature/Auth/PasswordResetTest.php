<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Notifications\ResetPasswordNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    private function tokenFor(User $user): string
    {
        $token = null;

        Notification::assertSentTo($user, ResetPasswordNotification::class, function ($notification) use (&$token) {
            $token = (fn () => $this->token)->call($notification);

            return true;
        });

        return $token;
    }

    public function test_reset_password_link_screen_can_be_rendered(): void
    {
        $this->get('/forgot-password')->assertOk();
    }

    public function test_reset_password_link_can_be_requested(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        $this->post('/forgot-password', ['email' => $user->email])
            ->assertSessionHas('status');

        Notification::assertSentTo($user, ResetPasswordNotification::class);
    }

    public function test_response_does_not_reveal_whether_email_exists(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        $existing = $this->post('/forgot-password', ['email' => $user->email]);
        $missing = $this->post('/forgot-password', ['email' => 'nadie@example.com']);

        $missing->assertSessionHasNoErrors();
        $this->assertSame(session('status'), $existing->getSession()->get('status'));
        $this->assertSame($existing->getSession()->get('status'), $missing->getSession()->get('status'));
    }

    public function test_reset_password_screen_can_be_rendered(): void
    {
        Notification::fake();

        $user = User::factory()->create();
        $this->post('/forgot-password', ['email' => $user->email]);

        $this->get('/reset-password/'.$this->tokenFor($user))->assertOk();
    }

    public function test_password_can_be_reset_with_valid_token(): void
    {
        Notification::fake();

        $user = User::factory()->create();
        $this->post('/forgot-password', ['email' => $user->email]);

        $this->post('/reset-password', [
            'token' => $this->tokenFor($user),
            'email' => $user->email,
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('login'));

        $this->assertTrue(Hash::check('new-password', $user->fresh()->password));
    }

    public function test_token_cannot_reset_another_users_password(): void
    {
        Notification::fake();

        $victim = User::factory()->create();
        $attacker = User::factory()->create();
        $this->post('/forgot-password', ['email' => $attacker->email]);

        $this->post('/reset-password', [
            'token' => $this->tokenFor($attacker),
            'email' => $victim->email,
            'password' => 'hijacked-password',
            'password_confirmation' => 'hijacked-password',
        ])->assertSessionHasErrors('email');

        $this->assertFalse(Hash::check('hijacked-password', $victim->fresh()->password));
    }

    public function test_forgot_password_is_rate_limited(): void
    {
        for ($i = 0; $i < 6; $i++) {
            $this->post('/forgot-password', ['email' => "x{$i}@example.com"]);
        }

        $this->post('/forgot-password', ['email' => 'y@example.com'])->assertStatus(429);
    }
}
