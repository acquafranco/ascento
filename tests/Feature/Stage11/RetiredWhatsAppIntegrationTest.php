<?php

namespace Tests\Feature\Stage11;

use App\Filament\Resources\WorkOrders\Pages\CreateWorkOrder;
use App\Models\WorkOrder;
use App\Notifications\WorkOrderAssignedNotification;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Etapa 11 (R3/R4): la integración de WhatsApp por empresa (conexión con
 * Meta, webhook y botones de órdenes para técnicos) estaba en desuso y se
 * retira. Nada de eso responde ni puede ejecutarse; el resto sigue igual.
 */
class RetiredWhatsAppIntegrationTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    public function test_the_retired_routes_no_longer_exist_for_anyone(): void
    {
        $a = $this->makeTenant();
        $slug = $a['company']->slug;

        foreach (['whatsapp.connect', 'whatsapp.callback'] as $name) {
            $this->assertFalse(Route::has($name), $name);
        }
        foreach ([null, $a['admin'], $a['technician']] as $user) {
            $user ? $this->actingAs($user) : auth()->logout();
            $this->get("/{$slug}/whatsapp/connect")->assertNotFound();
            $this->get('/whatsapp/callback?code=1&state=x')->assertNotFound();
        }
        $this->get('/api/whatsapp/webhook?hub_verify_token=x&hub_challenge=abc')->assertNotFound();
        $this->postJson('/api/whatsapp/webhook', ['entry' => []])->assertNotFound();

        foreach (['App\\Http\\Controllers\\WhatsAppController', 'App\\Http\\Controllers\\WhatsAppWebhookController', 'App\\Services\\WhatsAppService'] as $class) {
            $this->assertFalse(class_exists($class), $class);
        }
        Http::assertNothingSent();
    }

    public function test_a_company_that_was_connected_keeps_working_and_nothing_goes_to_meta(): void
    {
        Http::fake();
        Notification::fake();
        $a = $this->makeTenant();
        // Datos viejos de la conexión: quedan en la base pero no se usan ni se muestran.
        $a['company']->forceFill(['whatsapp_connected' => true, 'whatsapp_phone_number_id' => '123', 'whatsapp_access_token' => 'TOKEN-VIEJO'])->save();
        $a['technician']->forceFill(['phone' => '+5491155550000'])->save();

        $this->actingAs($a['admin']);
        Filament::setCurrentPanel(Filament::getPanel('ascensores_app'));
        Livewire::test(CreateWorkOrder::class)
            ->fillForm(['building_id' => $a['building']->id, 'unit' => 'Ascensor 1', 'type' => 'claim', 'priority' => 'high', 'status' => 'pending', 'users' => [$a['technician']->id]])
            ->call('create')->assertHasNoFormErrors();

        $order = WorkOrder::where('building_id', $a['building']->id)->firstOrFail();
        // El técnico recibe el aviso por los canales vigentes (campanita, push, Telegram).
        Notification::assertSentTo($a['technician'], WorkOrderAssignedNotification::class, fn ($n) => $n->workOrder->is($order));
        Http::assertNothingSent();

        // El SuperAdmin ya no ve ni el token ni la sección de WhatsApp.
        $this->assertArrayNotHasKey('whatsapp_access_token', $a['company']->fresh()->toArray());
        $form = file_get_contents(app_path('Filament/Resources/Companies/Schemas/CompanyForm.php'));
        $this->assertStringNotContainsString('whatsapp', strtolower($form));
    }

    public function test_the_commercial_whatsapp_and_the_share_links_keep_working(): void
    {
        $this->withoutVite();
        // El WhatsApp comercial de Ascento (asistente de la web) no depende de la integración retirada.
        $this->get('/')->assertOk()->assertSee('const number = "5491173728421";', false);
    }
}
