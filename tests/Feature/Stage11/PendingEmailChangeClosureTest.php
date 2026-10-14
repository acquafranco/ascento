<?php

namespace Tests\Feature\Stage11;

use App\Filament\Resources\Users\Pages\EditUser;
use App\Models\User;
use App\Notifications\ConfirmEmailChangeNotification;
use App\Notifications\EmailChangeRequestedNotification;
use App\Services\Auth\PendingEmailChange;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Notifications\Channels\MailChannel;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use RuntimeException;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Etapa 11, cierre del correo pendiente: lo que faltaba cubrir.
 */
class PendingEmailChangeClosureTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    private array $a;

    private User $tech;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->a = $this->makeTenant();
        $this->tech = $this->a['technician'];
        $this->tech->forceFill(['email' => 'viejo@empresa.test', 'email_verified_at' => now()->subDay(), 'password' => Hash::make('clave-actual-123'), 'phone' => '+5491155550001'])->saveQuietly();
        $this->a['admin']->forceFill(['phone' => '+5491155550002'])->saveQuietly();
        $this->tech = $this->tech->fresh();
    }

    private function profileUrl(): string
    {
        return '/'.$this->a['company']->slug.'/profile';
    }

    private function requestChange(string $email = 'nuevo@empresa.test'): TestResponse
    {
        $this->actingAs($this->tech->fresh());

        return $this->from($this->profileUrl())->patch($this->profileUrl(), ['name' => $this->tech->name, 'email' => $email, 'current_password' => 'clave-actual-123']);
    }

    private function linkTo(string $to): string
    {
        $url = null;
        Notification::assertSentOnDemand(ConfirmEmailChangeNotification::class, function ($n, $c, $notifiable) use ($to, &$url) {
            if (($notifiable->routes['mail'] ?? null) === $to) {
                $url = $n->url;
            }

            return true;
        });

        return (string) $url;
    }

    public function test_an_owner_correction_cancels_the_pending_request_and_the_old_link_cannot_overwrite_it(): void
    {
        $this->requestChange('pedido-tecnico@empresa.test');
        $link = $this->linkTo('pedido-tecnico@empresa.test');

        // El dueño corrige el correo del técnico desde el panel.
        $this->flushSession();
        $this->actingAs($this->a['admin']->fresh());
        Filament::setCurrentPanel(Filament::getPanel('ascensores_app'));
        Livewire::test(EditUser::class, ['record' => $this->tech->getRouteKey()])
            ->fillForm(['email' => 'corregido@empresa.test'])->call('save')->assertHasNoFormErrors();

        $tech = $this->tech->fresh();
        $this->assertSame('corregido@empresa.test', $tech->email);
        $this->assertNull($tech->pending_email, 'La corrección del dueño anula lo pendiente.');
        $this->assertNull($tech->pending_email_token);

        // El enlace viejo ya no pisa la corrección.
        auth()->logout();
        $this->flushSession();
        $this->get($link)->assertStatus(410);
        $this->assertSame('corregido@empresa.test', $this->tech->fresh()->email);
    }

    public function test_the_confirmation_itself_is_not_cancelled_by_the_new_rule(): void
    {
        $this->requestChange();
        auth()->logout();
        $this->flushSession();
        $this->get($this->linkTo('nuevo@empresa.test'))->assertOk();
        $this->assertSame('nuevo@empresa.test', $this->tech->fresh()->email);
    }

    public function test_a_failed_notice_to_the_current_email_does_not_cancel_a_request_already_sent(): void
    {
        // El enlace al correo nuevo sale; el aviso al correo actual falla.
        $mail = $this->mock(MailChannel::class);
        $mail->shouldReceive('send')->andReturnUsing(function ($notifiable, $notification) {
            if ($notification instanceof EmailChangeRequestedNotification) {
                throw new RuntimeException('SMTP caído para el aviso');
            }
        });
        Notification::swap(new ChannelManager(app()));
        $logged = [];
        Log::listen(function ($m) use (&$logged) {
            $logged[] = $m->message.' '.json_encode($m->context);
        });

        $this->requestChange()->assertSessionHasNoErrors();

        $tech = $this->tech->fresh();
        $this->assertSame('nuevo@empresa.test', $tech->pending_email, 'El pedido sigue: el enlace salió.');
        $this->assertSame('viejo@empresa.test', $tech->email);
        $all = implode("\n", $logged);
        $this->assertStringContainsString('No se pudo avisar al correo actual', $all);
        $this->assertStringNotContainsString('correo/confirmar', $all);
    }

    public function test_a_failed_send_still_counts_towards_the_request_limit(): void
    {
        $mail = $this->mock(MailChannel::class);
        $mail->shouldReceive('send')->andThrow(new RuntimeException('SMTP caído'));
        Notification::swap(new ChannelManager(app()));

        for ($i = 0; $i < PendingEmailChange::MAX_REQUESTS; $i++) {
            $this->requestChange("intento{$i}@empresa.test")->assertSessionHasErrors('email');
        }
        // Con el correo caído no se puede usar para mandar pedidos sin fin.
        $this->requestChange('otro@empresa.test')->assertSessionHasErrors('email');
        $this->assertStringContainsString('Pediste varios cambios', session('errors')->first('email'));
        $this->assertNull($this->tech->fresh()->pending_email);
    }

    public function test_a_double_submit_does_not_send_a_second_link(): void
    {
        $this->requestChange()->assertSessionHasNoErrors();
        $this->requestChange()->assertSessionHasNoErrors(); // mismo formulario enviado dos veces

        Notification::assertSentOnDemandTimes(ConfirmEmailChangeNotification::class, 1);
        // El único enlace mandado sigue sirviendo.
        auth()->logout();
        $this->flushSession();
        $this->get($this->linkTo('nuevo@empresa.test'))->assertOk();
    }

    public function test_the_profile_never_targets_another_account(): void
    {
        $other = User::factory()->technician()->create(['company_id' => $this->a['company']->id, 'email' => 'otro@empresa.test']);

        // Campos de otra cuenta en el pedido: se ignoran, solo cambia (pendiente) la propia.
        $this->actingAs($this->tech->fresh());
        $this->patch($this->profileUrl(), ['name' => 'X', 'email' => 'nuevo@empresa.test', 'current_password' => 'clave-actual-123', 'id' => $other->id, 'user_id' => $other->id]);
        $this->assertNull($other->fresh()->pending_email);
        $this->assertSame('otro@empresa.test', $other->fresh()->email);
        $this->assertSame('nuevo@empresa.test', $this->tech->fresh()->pending_email);
    }
}
