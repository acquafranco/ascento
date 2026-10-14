<?php

namespace Tests\Feature\Stage11;

use App\Models\WebhookEvent;
use App\Notifications\ResetPasswordNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Etapa 11, riesgos menores (R7, R8): cada uno reproducido y bloqueado.
 */
class MinorRisksTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    /*
    | R8. Con una sesión robada del técnico se cambiaba el correo sin la
    | contraseña; «Olvidé mi contraseña» mandaba el enlace al correo nuevo
    | (aunque no esté confirmado) y la cuenta quedaba tomada.
    */
    public function test_changing_the_email_requires_the_current_password(): void
    {
        Notification::fake();
        $a = $this->makeTenant();
        $tech = $a['technician'];
        $tech->forceFill(['password' => Hash::make('clave-actual-123'), 'email' => 'tecnico@empresa.test'])->save();
        $slug = $a['company']->slug;
        $this->actingAs($tech->fresh());

        // Sin la contraseña: no cambia.
        $this->from("/{$slug}/profile")->patch("/{$slug}/profile", ['name' => $tech->name, 'email' => 'atacante@ejemplo.test'])
            ->assertSessionHasErrors('current_password');
        $this->assertSame('tecnico@empresa.test', $tech->fresh()->email);

        // Con una contraseña equivocada, tampoco.
        $this->from("/{$slug}/profile")->patch("/{$slug}/profile", ['name' => $tech->name, 'email' => 'atacante@ejemplo.test', 'current_password' => 'otra-cosa-999'])
            ->assertSessionHasErrors('current_password');
        $this->assertSame('tecnico@empresa.test', $tech->fresh()->email);

        // El enlace de recuperación no se puede pedir para el correo del atacante
        // (la app usa su propia notificación, ResetPasswordNotification).
        auth()->logout();
        $this->flushSession();
        $this->post('/forgot-password', ['email' => 'atacante@ejemplo.test']);
        Notification::assertSentTimes(ResetPasswordNotification::class, 0);
        // Control: con el correo verdadero sí sale (la verificación no es vacía).
        $this->post('/forgot-password', ['email' => 'tecnico@empresa.test']);
        Notification::assertSentTo($tech->fresh(), ResetPasswordNotification::class);
    }

    public function test_the_technician_still_updates_the_name_freely_and_the_email_with_the_password(): void
    {
        $a = $this->makeTenant();
        $tech = $a['technician'];
        $tech->forceFill(['password' => Hash::make('clave-actual-123')])->save();
        $slug = $a['company']->slug;
        $this->actingAs($tech->fresh());

        // Solo el nombre: no pide la contraseña.
        $this->patch("/{$slug}/profile", ['name' => 'Nombre Nuevo', 'email' => $tech->email])->assertSessionHasNoErrors();
        $this->assertSame('Nombre Nuevo', $tech->fresh()->name);

        // El correo, con la contraseña: queda pendiente hasta confirmarlo desde la
        // dirección nueva (ver PendingEmailChangeTest); el actual no cambia.
        $this->patch("/{$slug}/profile", ['name' => 'Nombre Nuevo', 'email' => 'nuevo@empresa.test', 'current_password' => 'clave-actual-123'])->assertSessionHasNoErrors();
        $this->assertSame($tech->email, $tech->fresh()->email);
        $this->assertSame('nuevo@empresa.test', $tech->fresh()->pending_email);
    }

    /*
    | R7. Cada pedido al webhook de Mercado Pago sin firma válida dejaba una
    | fila en webhook_events, que se conserva 90 días: con 120 pedidos por
    | minuto, una sola IP podía sumar millones de filas.
    */
    public function test_unsigned_mercado_pago_requests_do_not_fill_the_database(): void
    {
        config(['services.mercadopago.webhook_secret' => 'clave-del-webhook']);
        $logged = 0;
        Log::listen(function ($message) use (&$logged) {
            if (str_contains($message->message, 'firma inválida')) {
                $logged++;
            }
        });

        for ($i = 0; $i < 30; $i++) {
            $this->withHeaders(['x-request-id' => 'r'.$i, 'x-signature' => 'ts=1,v1=falsa'])
                ->postJson('/api/mercadopago/webhook?data.id=X'.$i.'&type=subscription_preapproval')
                ->assertUnauthorized();
        }

        $this->assertSame(0, WebhookEvent::count());
        $this->assertSame(1, $logged, 'Un aviso por minuto en el log, no uno por pedido.');
    }

    /*
    | R2. CSP: solo en modo reporte y apagada por defecto. Nunca bloquea nada.
    */
    public function test_the_csp_is_report_only_and_off_unless_enabled(): void
    {
        $this->withoutVite();

        $off = $this->get('/');
        $this->assertFalse($off->headers->has('Content-Security-Policy-Report-Only'));
        $this->assertFalse($off->headers->has('Content-Security-Policy'));

        config(['app.csp_report_only' => true, 'broadcasting.connections.reverb.options.host' => 'ascento.online', 'broadcasting.connections.reverb.options.port' => 443]);
        $on = $this->get('/');
        $this->assertFalse($on->headers->has('Content-Security-Policy'), 'Nunca se aplica: solo reporta.');
        $policy = (string) $on->headers->get('Content-Security-Policy-Report-Only');
        foreach (["object-src 'none'", "base-uri 'self'", "form-action 'self'", "frame-ancestors 'self'", 'connect-src \'self\' wss://ascento.online ws://ascento.online', 'https://maps.geoapify.com'] as $directive) {
            $this->assertStringContainsString($directive, $policy);
        }
    }
}
