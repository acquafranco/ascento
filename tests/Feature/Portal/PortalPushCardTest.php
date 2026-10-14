<?php

namespace Tests\Feature\Portal;

use App\Models\Building;
use App\Models\Client;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Avisos en el portal: el inicio solo invita a activarlos mientras falte
 * hacerlo (sin «Probar» ni «Desactivar»); la tarjeta completa queda en Avisos.
 */
class PortalPushCardTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        config(['webpush.vapid.public_key' => 'clave-publica-de-prueba']);
        $a = $this->makeTenant();
        $client = Client::factory()->create(['company_id' => $a['company']->id, 'name' => 'Consorcio Uno']);
        $building = Building::factory()->create(['company_id' => $a['company']->id, 'client_id' => $client->id]);
        $this->user = User::factory()->create(['email' => 'uno@consorcio.test']);
        $this->user->forceFill(['role' => User::ROLE_CLIENT, 'company_id' => $client->company_id, 'client_id' => $client->id])->save();
        $this->user->portalBuildings()->sync([$building->id]);
    }

    public function test_the_portal_home_only_invites_to_turn_notifications_on(): void
    {
        $html = $this->actingAs($this->user)->get('/portal')->assertOk()->getContent();

        $this->assertStringContainsString('data-portal-push-prompt', $html);
        $this->assertStringContainsString('data-push-enable', $html);
        $this->assertStringContainsString('data-push-later', $html); // «Ahora no»: no insiste
        // Nada de probar el sistema en el inicio.
        $this->assertStringNotContainsString('@click="test()"', $html);
        $this->assertStringNotContainsString('@click="disable()"', $html);
        $this->assertStringNotContainsString('Activados en este dispositivo.', $html);
        // Solo se muestra mientras falta activarlos (o tras activarlos, el mensaje de confirmación).
        $this->assertStringContainsString("x-show=\"['default', 'error'].includes(state) || (state === 'subscribed' && message)\"", $html);
    }

    public function test_the_notifications_page_keeps_the_full_card(): void
    {
        $html = $this->actingAs($this->user)->get('/portal/notificaciones')->assertOk()->getContent();

        $this->assertStringContainsString('data-portal-push', $html);
        $this->assertStringNotContainsString('data-portal-push-prompt', $html);
        $this->assertStringContainsString('@click="test()"', $html);
        $this->assertStringContainsString('@click="disable()"', $html);
        $this->assertStringContainsString('Están bloqueados en este navegador.', $html);
    }
}
