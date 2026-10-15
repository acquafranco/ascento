<?php

namespace Tests\Feature\Location;

use App\Filament\Pages\BuildingsMap;
use App\Models\Client;
use App\Models\TechnicianLocation;
use App\Models\User;
use App\Services\Location\TechnicianLocationService;
use App\Services\Staff\StaffManager;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Ubicación de técnicos: voluntaria, solo la propia, solo la última posición,
 * y visible únicamente para la oficina de SU empresa con permiso «Técnicos».
 */
class TechnicianLocationTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    private array $a;

    private array $b;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->travelTo(Carbon::parse('2026-10-15 10:00'));
        $this->a = $this->makeTenant();
        $this->b = $this->makeTenant();
        $this->giveCompanyPlan($this->a['company'], 'empresa');
        $this->giveCompanyPlan($this->b['company'], 'empresa');
    }

    private function url(array $tenant, string $name): string
    {
        return route($name, ['company' => $tenant['company']->slug]);
    }

    private function asTech(array $tenant, ?User $tech = null): static
    {
        $this->flushSession();

        return $this->actingAs(($tech ?? $tenant['technician'])->fresh());
    }

    private function enableAndSend(array $tenant, float $lat = -34.6, float $lng = -58.4): void
    {
        $this->asTech($tenant)->post($this->url($tenant, 'location.enable'))->assertRedirect();
        $this->postJson($this->url($tenant, 'location.store'), ['latitude' => $lat, 'longitude' => $lng, 'accuracy' => 20])->assertOk()->assertJson(['result' => 'stored']);
    }

    private function mapData(User $viewer): array
    {
        $this->flushSession();
        $this->actingInPanel($viewer->fresh());

        return Livewire::test(BuildingsMap::class)->instance()->technicianLocations()['technicians'];
    }

    public function test_a_technician_enables_sends_and_disables_only_their_own_location(): void
    {
        // Sin activar en Ascento no se guarda nada aunque el navegador mande.
        $this->asTech($this->a)->postJson($this->url($this->a, 'location.store'), ['latitude' => -34.6, 'longitude' => -58.4])->assertStatus(409);
        $this->assertNull(TechnicianLocation::withoutGlobalScopes()->whereNotNull('latitude')->first());

        $this->enableAndSend($this->a);
        $row = TechnicianLocation::withoutGlobalScopes()->where('user_id', $this->a['technician']->id)->sole();
        $this->assertTrue($row->tracking_enabled);
        $this->assertSame((int) $this->a['company']->id, (int) $row->company_id);
        $this->assertSame(TechnicianLocation::ONLINE, $row->status());

        // Ids ajenos en el pedido se ignoran: siempre es la del técnico autenticado.
        $other = User::factory()->technician()->create(['company_id' => $this->a['company']->id]);
        $this->travel(1)->minutes();
        $this->postJson($this->url($this->a, 'location.store'), ['latitude' => -34.7, 'longitude' => -58.5, 'user_id' => $other->id, 'company_id' => $this->b['company']->id])->assertOk();
        $this->assertNull(TechnicianLocation::withoutGlobalScopes()->where('user_id', $other->id)->first());
        $this->assertSame(0, TechnicianLocation::withoutGlobalScopes()->where('company_id', $this->b['company']->id)->count());
        $this->assertEqualsWithDelta(-34.7, $row->fresh()->latitude, 0.0001);

        // Desactivar: deja de recibir y borra la última posición.
        $this->post($this->url($this->a, 'location.disable'))->assertRedirect();
        $row->refresh();
        $this->assertFalse($row->tracking_enabled);
        $this->assertNull($row->latitude);
        $this->travel(1)->minutes();
        $this->postJson($this->url($this->a, 'location.store'), ['latitude' => -34.6, 'longitude' => -58.4])->assertStatus(409);
        $this->assertNull($row->fresh()->latitude);
        $this->assertSame(TechnicianLocation::DISABLED, $row->fresh()->status());
    }

    public function test_invalid_coordinates_are_rejected_and_responses_never_echo_them(): void
    {
        $this->asTech($this->a)->post($this->url($this->a, 'location.enable'));
        foreach ([['latitude' => 91, 'longitude' => 0], ['latitude' => 0, 'longitude' => 181], ['latitude' => 'abc', 'longitude' => 1], ['latitude' => 0, 'longitude' => 0], ['latitude' => -34.6, 'longitude' => -58.4, 'accuracy' => 99999], ['longitude' => -58.4]] as $bad) {
            $res = $this->postJson($this->url($this->a, 'location.store'), $bad)->assertStatus(422);
            $this->assertStringNotContainsString('-58.4', $res->getContent());
        }
        $this->assertNull(TechnicianLocation::withoutGlobalScopes()->whereNotNull('latitude')->first());
    }

    public function test_sending_is_rate_limited_and_too_frequent_updates_are_ignored(): void
    {
        $this->enableAndSend($this->a);
        // Antes del intervalo mínimo: se ignora (no se pisa la hora).
        $this->postJson($this->url($this->a, 'location.store'), ['latitude' => -34.61, 'longitude' => -58.41])->assertOk()->assertJson(['result' => 'skipped']);
        for ($i = 0; $i < 6; $i++) {
            $this->postJson($this->url($this->a, 'location.store'), ['latitude' => -34.6, 'longitude' => -58.4]);
        }
        $this->postJson($this->url($this->a, 'location.store'), ['latitude' => -34.6, 'longitude' => -58.4])->assertStatus(429);
    }

    public function test_the_admin_sees_only_their_company_and_old_positions_are_marked_stale(): void
    {
        $this->enableAndSend($this->a);
        $this->enableAndSend($this->b, -31.4, -64.2);

        $data = collect($this->mapData($this->a['admin']));
        $this->assertSame([$this->a['technician']->id], $data->pluck('id')->all());
        $this->assertSame('online', $data->first()['status']);
        $this->assertNotNull($data->first()['position']);
        $this->assertStringNotContainsString('-31.4', json_encode($data->all()));

        // Seis minutos sin novedades: desactualizada, con su hora (la posición no se presenta como actual).
        $this->travel(6)->minutes();
        $stale = collect($this->mapData($this->a['admin']))->first();
        $this->assertSame('stale', $stale['status']);
        $this->assertFalse($stale['fresh']);
        $this->assertNotNull($stale['locatedLabel']);

        // Pasadas las horas de conservación, la posición se borra.
        $this->travel(13)->hours();
        $this->assertSame(2, app(TechnicianLocationService::class)->prune());
        $this->assertNull(collect($this->mapData($this->a['admin']))->first()['position']);
    }

    public function test_only_authorized_office_users_reach_the_technicians_map(): void
    {
        $this->enableAndSend($this->a);

        // Administrativo sin «Técnicos»: ni la pestaña ni los datos.
        $this->actingAs($this->a['admin']);
        $noTech = app(StaffManager::class)->create($this->a['admin'], ['name' => 'Sin técnicos', 'email' => 'nt@demo.test', 'password' => 'clave-segura-123'], ['buildings' => 'view']);
        $this->flushSession();
        $this->actingInPanel($noTech->fresh());
        $this->get(BuildingsMap::getUrl(['vista' => 'tecnicos']))->assertOk()->assertDontSee('data-technicians-shell', false);
        Livewire::test(BuildingsMap::class)->call('technicianLocations')->assertForbidden();

        // Con «Técnicos» (y Edificios para abrir el mapa): sí.
        $this->actingAs($this->a['admin']);
        $withTech = app(StaffManager::class)->create($this->a['admin'], ['name' => 'Con técnicos', 'email' => 'ct@demo.test', 'password' => 'clave-segura-123'], ['buildings' => 'view', 'technicians' => 'view']);
        $this->assertCount(1, $this->mapData($withTech));

        // Un técnico no entra al panel ni a la ubicación de otro.
        $res = $this->asTech($this->a)->get(BuildingsMap::getUrl(['vista' => 'tecnicos']));
        $this->assertNotSame(200, $res->getStatusCode());
        $this->assertStringNotContainsString('-58.4', $res->getContent());

        // Un técnico de otra empresa no puede escribir con el enlace de esta empresa.
        $this->asTech($this->b)->postJson($this->url($this->a, 'location.store'), ['latitude' => -34.6, 'longitude' => -58.4]);
        $this->assertSame(0, TechnicianLocation::withoutGlobalScopes()->where('company_id', $this->b['company']->id)->count());

        // Un usuario del portal tampoco ve ni escribe ubicaciones.
        $client = Client::withoutGlobalScopes()->find($this->a['building']->client_id);
        $portal = User::factory()->create(['email' => 'portal@demo.test']);
        $portal->forceFill(['role' => User::ROLE_CLIENT, 'company_id' => $this->a['company']->id, 'client_id' => $client->id])->save();
        $this->flushSession();
        $this->actingAs($portal->fresh());
        $this->assertNotSame(200, $this->postJson($this->url($this->a, 'location.store'), ['latitude' => -34.6, 'longitude' => -58.4])->getStatusCode());
        $this->assertNotSame(200, $this->get(BuildingsMap::getUrl(['vista' => 'tecnicos']))->getStatusCode());
        $this->get(route('portal.home'))->assertDontSee('-58.4');
    }

    public function test_no_endpoint_works_without_a_session_and_logging_out_clears_the_position(): void
    {
        $this->post($this->url($this->a, 'location.store'), ['latitude' => -34.6, 'longitude' => -58.4])->assertRedirect();

        $this->enableAndSend($this->a);
        Log::spy();
        $this->post(route('logout'));
        $row = TechnicianLocation::withoutGlobalScopes()->where('user_id', $this->a['technician']->id)->sole();
        $this->assertNull($row->latitude);
        $this->assertTrue($row->tracking_enabled); // su decisión se conserva; la posición, no
        Log::shouldNotHaveReceived('info', [\Mockery::on(fn ($m) => str_contains((string) $m, '-34.6'))]);
    }

    public function test_permission_and_errors_are_reported_without_coordinates(): void
    {
        $this->asTech($this->a)->post($this->url($this->a, 'location.enable'));
        $this->postJson($this->url($this->a, 'location.report'), ['permission' => 'denied'])->assertOk();
        $row = TechnicianLocation::withoutGlobalScopes()->where('user_id', $this->a['technician']->id)->sole();
        $this->assertSame(TechnicianLocation::PERMISSION_DENIED, $row->status());

        $this->postJson($this->url($this->a, 'location.report'), ['permission' => 'granted', 'error' => 'timeout'])->assertOk();
        $this->assertSame(TechnicianLocation::ERROR, $row->fresh()->status());
        $this->postJson($this->url($this->a, 'location.report'), ['error' => 'cualquier-cosa'])->assertStatus(422);

        // El perfil explica las dos condiciones (Ascento y el permiso del celular).
        $this->get(route('profile.edit', ['company' => $this->a['company']->slug]))->assertOk()
            ->assertSee('solo mientras Ascento está abierto en la pantalla', false)
            ->assertSee('Permiso de este celular');
    }
}
