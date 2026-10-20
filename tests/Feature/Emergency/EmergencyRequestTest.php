<?php

namespace Tests\Feature\Emergency;

use App\Filament\Resources\Emergency\EmergencyRequestResource;
use App\Filament\Resources\Emergency\Pages\ListEmergencyRequests;
use App\Filament\Resources\Emergency\Pages\ViewEmergencyRequest;
use App\Filament\Resources\Emergency\RelationManagers\DeliveriesRelationManager;
use App\Models\Building;
use App\Models\BuildingPublicPage;
use App\Models\Client;
use App\Models\EmergencyDelivery;
use App\Models\EmergencyRequest;
use App\Models\User;
use App\Services\Emergency\EmergencyRequestService;
use App\Services\PublicQr\PublicQrService;
use App\Services\Staff\StaffManager;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Pedido de ayuda por atrapamiento desde el QR: RETIRADO (Ascento Conecta).
 *
 * - La página pública no lo ofrece y la hoja impresa no lo menciona.
 * - La dirección de envío (una página vieja abierta en un teléfono) no
 *   registra nada ni avisa a nadie, y lo dice claro.
 * - Lo registrado antes se conserva: el panel lo ve y lo cierra, el portal lo
 *   ve solo en sus edificios, otra empresa no lo ve, y los correos fallidos
 *   de esos pedidos se siguen reintentando.
 */
class EmergencyRequestTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    private array $a;

    private array $b;

    private BuildingPublicPage $page;

    private User $consortium;        // portal del cliente, con el edificio autorizado

    private User $otherBuildingUser; // mismo cliente, sin este edificio

    private User $staffWo;           // administrativo con «Órdenes de trabajo» (ver)

    private User $staffOther;        // administrativo sin ese permiso

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->travelTo(Carbon::parse('2026-10-15 22:00'));
        $this->b = $this->makeTenant();
        $this->a = $this->makeTenant();
        $this->giveCompanyPlan($this->a['company'], 'empresa');
        $this->giveCompanyPlan($this->b['company'], 'empresa');

        $client = Client::find($this->a['building']->client_id);
        $this->consortium = $this->portalUser($client, [$this->a['building']->id], 'admin@consorcio.test');
        $other = Building::factory()->create(['company_id' => $this->a['company']->id, 'client_id' => $client->id]);
        $this->otherBuildingUser = $this->portalUser($client, [$other->id], 'otro@consorcio.test');

        $this->actingInPanel($this->a['admin']);
        $this->staffWo = app(StaffManager::class)->create($this->a['admin'], ['name' => 'Oficina OT', 'email' => 'ot@empresa.test', 'password' => 'clave-segura-123'], ['work_orders' => 'view']);
        $this->staffOther = app(StaffManager::class)->create($this->a['admin'], ['name' => 'Oficina Stock', 'email' => 'stock@empresa.test', 'password' => 'clave-segura-123'], ['stock' => 'view']);
        $this->page = app(PublicQrService::class)->generate($this->a['admin'], $this->a['building']);
        // Aunque una empresa lo hubiera encendido antes, ya no se ofrece.
        $this->a['company']->forceFill(['emergency_requests_enabled' => true])->save();
        auth()->logout();
        $this->flushSession();
    }

    private function portalUser(Client $client, array $buildingIds, string $email): User
    {
        $user = User::factory()->create(['email' => $email]);
        $user->forceFill(['role' => User::ROLE_CLIENT, 'company_id' => $client->company_id, 'client_id' => $client->id])->save();
        $user->portalBuildings()->sync($buildingIds);

        return $user;
    }

    /** Un pedido registrado ANTES del retiro (con sus avisos), como quedó en la base. */
    private function historic(): EmergencyRequest
    {
        $request = new EmergencyRequest;
        $request->forceFill([
            'company_id' => $this->a['company']->id, 'client_id' => $this->a['building']->client_id, 'building_id' => $this->a['building']->id,
            'reference' => 'E-'.strtoupper(Str::random(6)), 'idempotency_key' => hash('sha256', Str::random()), 'status' => EmergencyRequest::OPEN,
        ]);
        EmergencyRequest::withoutEvents(fn () => $request->save());
        app(EmergencyRequestService::class)->notify($request->fresh());

        return $request->fresh();
    }

    public function test_the_public_page_and_print_sheet_no_longer_offer_it(): void
    {
        $html = $this->get(route('public-qr.show', ['token' => $this->page->token]))->assertOk()->getContent();

        $this->assertStringNotContainsString('Estoy atrapado', $html);
        $this->assertStringNotContainsString(route('public-qr.help', ['token' => $this->page->token]), $html);
        $this->assertStringNotContainsString('data-qr="help"', $html);
        $this->assertFalse(app(EmergencyRequestService::class)->availableFor($this->page));

        $this->actingInPanel($this->a['admin']);
        $sheet = $this->get(route('public-qr.sheet', ['building' => $this->a['building']->id, 'size' => 'a4']))->assertOk()->getContent();
        $this->assertStringNotContainsString('¿Quedaste atrapado?', $sheet);
        $this->assertStringNotContainsString('Estoy atrapado', $sheet);
        $this->assertStringContainsString('usá el botón de alarma o el intercomunicador del ascensor', $sheet);
        $this->assertStringContainsString('911', $sheet);
    }

    public function test_an_old_page_that_still_sends_it_registers_and_notifies_nothing(): void
    {
        // Comprobante válido de una página abierta antes del retiro.
        $nonce = app(EmergencyRequestService::class)->issueNonce($this->page);
        $url = route('public-qr.help', ['token' => $this->page->token]);

        $this->post($url, ['k' => $nonce])->assertStatus(410)
            ->assertSee('Esta opción ya no está disponible')
            ->assertSee('No se registró ningún pedido y no se avisó a nadie')
            ->assertSee('911');

        // El envío de la página vieja (JavaScript) recibe el mismo mensaje para mostrar.
        $json = $this->postJson($url, ['k' => $nonce])->assertStatus(410)->json();
        $this->assertSame('discontinued', $json['state']);
        $this->assertStringContainsString('Esta opción ya no está disponible', $json['html']);

        // Un token inventado tampoco registra nada.
        $this->post(route('public-qr.help', ['token' => str_repeat('A', PublicQrService::TOKEN_LENGTH)]), ['k' => $nonce])->assertStatus(410);

        $this->assertSame(0, EmergencyRequest::withoutGlobalScopes()->count());
        $this->assertSame(0, DatabaseNotification::count());
        $this->assertSame(0, EmergencyDelivery::withoutGlobalScopes()->count());
    }

    public function test_history_is_kept_and_isolated(): void
    {
        $request = $this->historic();

        // Empresa: el dueño ve sus avisos y lo cierra.
        $this->actingInPanel($this->a['admin']);
        $this->assertTrue(EmergencyRequestResource::shouldRegisterNavigation());
        Livewire::test(ListEmergencyRequests::class)->assertCanSeeTableRecords([$request]);
        Livewire::test(ViewEmergencyRequest::class, ['record' => $request->getRouteKey()])->assertSee($request->reference);
        Livewire::test(DeliveriesRelationManager::class, ['ownerRecord' => $request, 'pageClass' => ViewEmergencyRequest::class])->assertCountTableRecords(3);
        Livewire::test(ListEmergencyRequests::class)->callTableAction('close', $request, data: ['status' => 'attended', 'note' => 'Liberada 22:30']);
        $this->assertSame(['attended', $this->a['admin']->id], [$request->fresh()->status, $request->fresh()->closed_by]);

        // Administrativo con «ver» de Órdenes: ve, no cierra; sin ese permiso: no entra.
        $request->forceFill(['status' => 'open', 'closed_at' => null])->save();
        $this->flushSession();
        $this->actingInPanel($this->staffWo);
        $this->get(EmergencyRequestResource::getUrl('view', ['record' => $request]))->assertOk();
        Livewire::test(ListEmergencyRequests::class)->assertTableActionHidden('close', $request);
        $this->flushSession();
        $this->actingInPanel($this->staffOther);
        $this->get(EmergencyRequestResource::getUrl('index'))->assertForbidden();

        // Otra empresa: no la ve.
        $this->flushSession();
        $this->actingInPanel($this->b['admin']);
        $this->get(EmergencyRequestResource::getUrl('view', ['record' => $request]))->assertNotFound();
        Livewire::test(ListEmergencyRequests::class)->assertCanNotSeeTableRecords([$request]);

        // Portal: el consorcio del edificio sí; el mismo cliente sin ese edificio, no.
        $this->flushSession();
        $this->actingAs($this->consortium)->get(route('portal.emergencies'))->assertOk()->assertSee($request->reference)->assertDontSee('Liberada');
        $this->flushSession();
        $this->actingAs($this->otherBuildingUser)->get(route('portal.emergencies'))->assertOk()->assertDontSee($request->reference);
    }

    public function test_failed_mail_of_old_requests_is_still_retried_without_duplicating(): void
    {
        $this->historic();
        $note = DatabaseNotification::where('notifiable_id', $this->a['admin']->id)->sole();
        $note->forceFill(['mailed_at' => null, 'mail_failed_at' => now()])->save();

        Artisan::call('emergency:retry-mail');
        Artisan::call('emergency:retry-mail'); // el segundo no reenvía: ya salió

        $this->assertNotNull($note->fresh()->mailed_at);
        $this->assertSame(2, EmergencyDelivery::withoutGlobalScopes()->where('user_id', $this->a['admin']->id)->sole()->mail_attempts);
    }

    public function test_logos_are_public_only_and_the_print_sheet_is_self_contained(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('companies/logos/logo.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='));
        $this->a['company']->forceFill(['logo' => 'companies/logos/logo.png', 'primary_color' => '#123456'])->save();

        $html = $this->get(route('public-qr.show', ['token' => $this->page->token]))->assertOk()->getContent();
        $this->assertStringContainsString('/storage/companies/logos/logo.png', $html);
        $this->assertStringContainsString('--qr-accent: #123456', $html);
        $this->assertStringNotContainsString('storage/app', $html);
        $this->assertStringContainsString('images/brand/logo-64.png', $html);

        $this->actingInPanel($this->a['admin']);
        $sheet = $this->get(route('public-qr.sheet', ['building' => $this->a['building']->id, 'size' => 'a4']))->assertOk()->getContent();
        $this->assertStringContainsString('<svg', $sheet);
        $this->assertStringContainsString('data:image/png;base64,', $sheet);
        $this->assertStringContainsString('Funciona con <strong>Ascento</strong>', $sheet);
        $this->assertStringContainsString('Reportar un problema', $sheet);
        $this->assertDoesNotMatchRegularExpression('#<img[^>]+src="https?://#', $sheet);

        $this->flushSession();
        $this->actingInPanel($this->staffOther);
        $this->get(route('public-qr.sheet', ['building' => $this->a['building']->id, 'size' => 'a4']))->assertNotFound();
        $this->flushSession();
        $this->actingInPanel($this->b['admin']);
        $this->get(route('public-qr.sheet', ['building' => $this->a['building']->id, 'size' => 'a4']))->assertNotFound();
    }
}
