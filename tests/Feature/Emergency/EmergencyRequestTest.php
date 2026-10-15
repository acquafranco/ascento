<?php

namespace Tests\Feature\Emergency;

use App\Filament\Pages\CompanySettings;
use App\Filament\Resources\Emergency\EmergencyRequestResource;
use App\Filament\Resources\Emergency\Pages\ListEmergencyRequests;
use App\Filament\Resources\Emergency\Pages\ViewEmergencyRequest;
use App\Filament\Resources\Emergency\RelationManagers\DeliveriesRelationManager;
use App\Models\Building;
use App\Models\BuildingPublicPage;
use App\Models\Client;
use App\Models\Elevator;
use App\Models\EmergencyDelivery;
use App\Models\EmergencyRequest;
use App\Models\OnCallSchedule;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Notifications\App\EmergencyRequestNotification;
use App\Services\Emergency\EmergencyRequestService;
use App\Services\OnCall\OnCallService;
use App\Services\PublicQr\PublicQrService;
use App\Services\Staff\StaffManager;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Pedido de ayuda por atrapamiento desde el QR público: disponibilidad (plan,
 * interruptor, QR), registro e idempotencia, defensas contra abuso,
 * destinatarios reales, entrega y reintentos, y aislamiento entre empresas,
 * consorcios y técnicos.
 */
class EmergencyRequestTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    private array $a;

    private array $b;

    private Client $client;

    private BuildingPublicPage $page;

    private User $consortium;      // portal del cliente, con el edificio autorizado

    private User $otherBuildingUser; // mismo cliente, sin este edificio

    private User $otherClientUser;   // otro cliente de la misma empresa

    private User $staffWo;           // administrativo con «Órdenes de trabajo»

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

        $this->client = Client::find($this->a['building']->client_id);
        $this->consortium = $this->portalUser($this->client, [$this->a['building']->id], 'admin@consorcio.test');
        $other = Building::factory()->create(['company_id' => $this->a['company']->id, 'client_id' => $this->client->id]);
        $this->otherBuildingUser = $this->portalUser($this->client, [$other->id], 'otro@consorcio.test');
        $otherClient = Client::factory()->create(['company_id' => $this->a['company']->id]);
        $this->otherClientUser = $this->portalUser($otherClient, [Building::factory()->create(['company_id' => $this->a['company']->id, 'client_id' => $otherClient->id])->id], 'ajeno@consorcio.test');

        $this->actingInPanel($this->a['admin']);
        $this->staffWo = app(StaffManager::class)->create($this->a['admin'], ['name' => 'Oficina OT', 'email' => 'ot@empresa.test', 'password' => 'clave-segura-123'], ['work_orders' => 'view']);
        $this->staffOther = app(StaffManager::class)->create($this->a['admin'], ['name' => 'Oficina Stock', 'email' => 'stock@empresa.test', 'password' => 'clave-segura-123'], ['stock' => 'view']);
        $this->page = app(PublicQrService::class)->generate($this->a['admin'], $this->a['building']);
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

    private function publicPage(?BuildingPublicPage $page = null)
    {
        return $this->get(route('public-qr.show', ['token' => ($page ?? $this->page)->fresh()->token]));
    }

    private function nonce(?BuildingPublicPage $page = null): string
    {
        $html = $this->publicPage($page)->assertOk()->getContent();
        $this->assertSame(1, preg_match('/name="k" value="([^"]+)"/', $html, $m), 'La página no tiene el botón de ayuda.');

        return html_entity_decode($m[1]);
    }

    private function submit(?string $nonce, array $extra = [], bool $json = false, ?BuildingPublicPage $page = null)
    {
        $url = route('public-qr.help', ['token' => ($page ?? $this->page)->fresh()->token]);

        return $json
            ? $this->postJson($url, ['k' => $nonce, ...$extra])
            : $this->post($url, ['k' => $nonce, ...$extra]);
    }

    private function notifiedIds(): array
    {
        return DatabaseNotification::where('type', EmergencyRequestNotification::class)->pluck('notifiable_id')->sort()->values()->all();
    }

    /*
    |--------------------------------------------------------------------------
    | DISPONIBILIDAD
    |--------------------------------------------------------------------------
    */

    public function test_the_button_shows_only_with_plan_switch_and_active_qr(): void
    {
        $this->publicPage()->assertOk()->assertSee('Estoy atrapado en el ascensor')->assertSee('911')->assertSee('Tecnología de gestión por');

        // Interruptor apagado: sin botón.
        $this->a['company']->forceFill(['emergency_requests_enabled' => false])->save();
        $this->publicPage()->assertOk()->assertDontSee('Estoy atrapado en el ascensor');
        $this->a['company']->forceFill(['emergency_requests_enabled' => true])->save();

        // Plan Inicial: sin botón aunque el interruptor esté encendido.
        $this->giveCompanyPlan($this->a['company'], 'inicial');
        $this->publicPage()->assertOk()->assertDontSee('Estoy atrapado en el ascensor');

        // Profesional: sí.
        $this->giveCompanyPlan($this->a['company'], 'profesional');
        $this->publicPage()->assertOk()->assertSee('Estoy atrapado en el ascensor');

        // QR desactivado o edificio desactivado: la página no existe.
        $this->a['building']->delete();
        $this->publicPage()->assertNotFound()->assertDontSee('Estoy atrapado');
    }

    /*
    |--------------------------------------------------------------------------
    | REGISTRO, DESTINATARIOS Y ENTREGA
    |--------------------------------------------------------------------------
    */

    public function test_a_legitimate_request_is_registered_and_only_the_right_people_are_notified(): void
    {
        // Técnico de guardia ahora (las guardias de la empresa).
        $this->actingInPanel($this->a['admin']);
        $schedule = OnCallSchedule::create(['name' => 'Noche', 'days' => [1, 2, 3, 4, 5, 6, 7], 'starts_time' => '18:00', 'ends_time' => '08:00', 'technicians_needed' => 1, 'mode' => 'manual', 'is_active' => true]);
        app(OnCallService::class)->assign($schedule, $this->a['technician'], now()->subHour(), now()->addDay());
        auth()->logout();
        $this->flushSession();
        $elevator = Elevator::withoutGlobalScopes()->where('building_id', $this->a['building']->id)->orderBy('id')->first();

        $this->submit($this->nonce(), ['ascensor' => $elevator->id])->assertOk()
            ->assertSee('Registramos tu pedido de ayuda')->assertSee('no puede confirmar que ya lo hayan visto')->assertDontSee('Solicitud atendida');

        $request = EmergencyRequest::withoutGlobalScopes()->sole();
        $this->assertSame([$this->a['company']->id, $this->client->id, $this->a['building']->id, $elevator->id, 'open'],
            [$request->company_id, $request->client_id, $request->building_id, $request->elevator_id, $request->status]);
        $this->assertMatchesRegularExpression('/^E-[A-Z0-9]{6}$/', $request->reference);

        $expected = collect([$this->a['admin']->id, $this->staffWo->id, $this->a['technician']->id, $this->consortium->id])->sort()->values()->all();
        $this->assertSame($expected, $this->notifiedIds());
        foreach ([$this->staffOther, $this->otherBuildingUser, $this->otherClientUser, $this->b['admin'], $this->b['technician']] as $nobody) {
            $this->assertNotContains($nobody->id, $this->notifiedIds(), $nobody->email);
        }
        $this->assertSame(4, $request->recipients_count);
        $this->assertSame(['company', 'company', 'consortium', 'technician'], EmergencyDelivery::withoutGlobalScopes()->orderBy('audience')->pluck('audience')->all());

        // Contenido mínimo: lugar, hora, código; enlaces al acceso autorizado de cada uno.
        $note = DatabaseNotification::where('notifiable_id', $this->consortium->id)->sole();
        $this->assertStringContainsString($request->reference, $note->data['body']);
        $this->assertSame(route('portal.emergencies', [], false), $note->data['path']);
        $this->assertStringContainsString('/admin/pedidos-de-ayuda/', DatabaseNotification::where('notifiable_id', $this->a['admin']->id)->sole()->data['path']);
        $this->assertNotNull(DatabaseNotification::where('notifiable_id', $this->a['admin']->id)->sole()->mailed_at); // aceptado por el servidor de correo
    }

    public function test_json_response_reports_the_real_state(): void
    {
        $this->submit($this->nonce(), [], true)->assertOk()->assertJson(['state' => 'registered'])->assertJsonPath('html', fn ($html) => str_contains($html, 'data-help-result="registered"'));
    }

    public function test_with_nobody_to_notify_the_request_is_kept_and_the_person_is_told(): void
    {
        User::whereIn('id', [$this->a['admin']->id, $this->staffWo->id, $this->consortium->id])->get()->each->delete();

        $this->submit($this->nonce())->assertOk()->assertSee('no pudimos avisar a nadie')->assertSee('911');
        $this->assertSame(1, EmergencyRequest::withoutGlobalScopes()->count());
    }

    public function test_failed_mail_is_retried_without_duplicating(): void
    {
        $this->submit($this->nonce())->assertOk();
        $note = DatabaseNotification::where('notifiable_id', $this->a['admin']->id)->sole();
        $note->forceFill(['mailed_at' => null, 'mail_failed_at' => now()])->save();

        Artisan::call('emergency:retry-mail');
        Artisan::call('emergency:retry-mail'); // el segundo no reenvía: ya salió

        $this->assertNotNull($note->fresh()->mailed_at);
        $this->assertSame(2, EmergencyDelivery::withoutGlobalScopes()->where('user_id', $this->a['admin']->id)->sole()->mail_attempts);
        $this->assertSame(1, DatabaseNotification::where('notifiable_id', $this->a['admin']->id)->count());
    }

    /*
    |--------------------------------------------------------------------------
    | DUPLICADOS Y ABUSO
    |--------------------------------------------------------------------------
    */

    public function test_double_click_and_http_retries_return_the_same_request(): void
    {
        $nonce = $this->nonce();
        $first = $this->submit($nonce)->assertOk()->getContent();
        $this->submit($nonce)->assertOk()->assertSee(EmergencyRequest::withoutGlobalScopes()->sole()->reference);
        $this->submit($nonce, [], true)->assertOk();

        $this->assertSame(1, EmergencyRequest::withoutGlobalScopes()->count());
        $this->assertSame(0, EmergencyRequest::withoutGlobalScopes()->sole()->repeat_count);
        $this->assertCount(3, $this->notifiedIds()); // una sola ronda: dueño, administrativo de órdenes y consorcio
        $this->assertStringContainsString('Registramos tu pedido de ayuda', $first);
    }

    public function test_a_simultaneous_duplicate_is_resolved_by_the_unique_key(): void
    {
        $nonce = $this->nonce();
        $service = app(EmergencyRequestService::class);
        // El otro pedido «ganó» la carrera: ya hay una fila con esa clave.
        $first = $service->submit($this->page->fresh(), $nonce, null, '10.0.0.1');
        $second = $service->submit($this->page->fresh(), $nonce, null, '10.0.0.2');

        $this->assertSame(['created', 'duplicate'], [$first['status'], $second['status']]);
        $this->assertSame($first['request']->id, $second['request']->id);
        $this->assertSame(1, EmergencyRequest::withoutGlobalScopes()->count());
    }

    public function test_repeated_requests_join_the_open_incident_and_re_alert_at_most_every_10_minutes(): void
    {
        $this->submit($this->nonce())->assertOk();
        $this->submit($this->nonce())->assertOk()->assertSee('Tu pedido se sumó al que ya estaba registrado');
        $request = EmergencyRequest::withoutGlobalScopes()->sole();
        $this->assertSame([1, 1], [$request->repeat_count, $request->notify_rounds]);
        $this->assertCount(3, $this->notifiedIds());

        $this->travel(11)->minutes();
        $this->submit($this->nonce())->assertOk();
        $this->assertSame([2, 2], [$request->fresh()->repeat_count, $request->fresh()->notify_rounds]);
        $this->assertCount(6, $this->notifiedIds()); // segunda ronda: «Siguen pidiendo ayuda»

        // Cerrado el incidente, un pedido nuevo es un incidente nuevo.
        $request->fresh()->forceFill(['status' => 'attended', 'closed_at' => now()])->save();
        $this->submit($this->nonce())->assertOk()->assertSee('Registramos tu pedido de ayuda');
        $this->assertSame(2, EmergencyRequest::withoutGlobalScopes()->count());
    }

    public function test_caps_per_building_never_block_an_open_emergency(): void
    {
        for ($i = 0; $i < EmergencyRequestService::MAX_NEW_PER_BUILDING_HOUR; $i++) {
            $this->submit($this->nonce())->assertOk();
            EmergencyRequest::withoutGlobalScopes()->latest('id')->first()->forceFill(['status' => 'false_alarm', 'closed_at' => now()])->save();
        }
        // Tope alcanzado y sin incidente abierto: no se crea otro (respuesta genérica, sin detalles internos).
        $this->submit($this->nonce())->assertOk()->assertSee('No pudimos registrar el pedido desde acá')->assertDontSee('tope')->assertDontSee('límite');
        $this->assertSame(3, EmergencyRequest::withoutGlobalScopes()->count());

        // Con un incidente abierto, el pedido se suma aunque haya tope.
        EmergencyRequest::withoutGlobalScopes()->latest('id')->first()->forceFill(['status' => 'open', 'closed_at' => null])->save();
        $this->submit($this->nonce())->assertOk()->assertSee('Tu pedido se sumó');
    }

    public function test_ip_rate_limit(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->submit('basura-'.$i)->assertOk();
        }
        $this->submit('basura-11')->assertStatus(429);
        $this->assertSame(0, EmergencyRequest::withoutGlobalScopes()->count());
    }

    public function test_manipulated_or_invalid_requests_never_create_or_redirect_anything(): void
    {
        $nonce = $this->nonce();

        // Sin comprobante, con uno inventado, de otro QR, o vencido.
        $this->submit(null)->assertOk()->assertSee('No pudimos registrar el pedido');
        $this->submit(str_repeat('a', 24).'.'.now()->timestamp.'.'.str_repeat('0', 64))->assertOk()->assertSee('No pudimos registrar');
        $this->actingInPanel($this->b['admin']);
        $otherPage = app(PublicQrService::class)->generate($this->b['admin'], $this->b['building']);
        $this->b['company']->forceFill(['emergency_requests_enabled' => true])->save();
        auth()->logout();
        $this->flushSession();
        $this->submit($this->nonce($otherPage))->assertOk()->assertSee('No pudimos registrar'); // comprobante de otro QR en este
        $this->travel(4)->hours();
        $this->submit($nonce)->assertOk()->assertSee('La página quedó vencida');
        $this->assertSame(0, EmergencyRequest::withoutGlobalScopes()->count());

        // Ascensor de otro edificio y campos inventados: se ignoran; edificio y empresa salen del QR.
        $foreignElevator = Elevator::withoutGlobalScopes()->where('building_id', $this->b['building']->id)->first();
        $this->submit($this->nonce(), ['ascensor' => $foreignElevator->id, 'company_id' => $this->b['company']->id, 'building_id' => $this->b['building']->id, 'notify' => 'x@x.test'])->assertOk();
        $request = EmergencyRequest::withoutGlobalScopes()->sole();
        $this->assertSame([$this->a['company']->id, $this->a['building']->id, null], [$request->company_id, $request->building_id, $request->elevator_id]);
        $this->assertNotContains($this->b['admin']->id, $this->notifiedIds());

        // Token inexistente: 404 genérico.
        $this->post(route('public-qr.help', ['token' => str_repeat('Z', 48)]), ['k' => $nonce])->assertNotFound();
    }

    public function test_the_server_rejects_when_the_plan_switch_or_subscription_do_not_allow_it(): void
    {
        $nonce = app(EmergencyRequestService::class)->issueNonce($this->page->fresh());

        $this->giveCompanyPlan($this->a['company'], 'inicial');
        $this->submit($nonce)->assertOk()->assertSee('No pudimos registrar');

        $this->giveCompanyPlan($this->a['company'], 'profesional');
        $this->a['company']->forceFill(['emergency_requests_enabled' => false])->save();
        $this->submit($nonce)->assertOk()->assertSee('No pudimos registrar');

        $this->a['company']->forceFill(['emergency_requests_enabled' => true])->save();
        Subscription::where('company_id', $this->a['company']->id)->update(['status' => 'cancelled', 'current_period_end' => now()->subDay()]);
        $this->a['company']->forgetPlan();
        $this->submit($nonce)->assertOk()->assertSee('No pudimos registrar');
        $this->assertSame(0, EmergencyRequest::withoutGlobalScopes()->count());

        // Con todo en regla, el mismo comprobante sí registra (no quedó «quemado»).
        $this->giveCompanyPlan($this->a['company'], 'profesional');
        $this->submit($nonce)->assertOk()->assertSee('Registramos tu pedido');
    }

    public function test_a_logged_in_user_of_another_company_cannot_move_the_request(): void
    {
        $nonce = $this->nonce();
        $this->actingAs($this->b['technician']);

        $this->submit($nonce)->assertOk()->assertSee('Registramos tu pedido');

        $this->assertSame($this->a['company']->id, EmergencyRequest::withoutGlobalScopes()->sole()->company_id);
        $this->assertSame([$this->a['company']->id], EmergencyDelivery::withoutGlobalScopes()->distinct()->pluck('company_id')->all());
    }

    /*
    |--------------------------------------------------------------------------
    | CONSULTA: PANEL, PORTAL Y TÉCNICOS
    |--------------------------------------------------------------------------
    */

    public function test_who_can_see_and_close_requests(): void
    {
        $this->submit($this->nonce())->assertOk();
        $request = EmergencyRequest::withoutGlobalScopes()->sole();

        // Empresa: el dueño ve y cierra.
        $this->actingInPanel($this->a['admin']);
        Livewire::test(ListEmergencyRequests::class)->assertCanSeeTableRecords([$request]);
        Livewire::test(ViewEmergencyRequest::class, ['record' => $request->getRouteKey()])->assertSee($request->reference);
        Livewire::test(DeliveriesRelationManager::class, ['ownerRecord' => $request, 'pageClass' => ViewEmergencyRequest::class])
            ->assertCountTableRecords(3)->assertSee('correo aceptado por el servidor de correo');
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

        // Técnico: no entra al panel.
        $this->flushSession();
        $this->actingAs($this->a['technician'])->get(EmergencyRequestResource::getUrl('index'))->assertRedirect();

        // Portal: el consorcio del edificio sí; el mismo cliente sin ese edificio y otro cliente, no.
        $this->flushSession();
        $this->actingAs($this->consortium)->get(route('portal.emergencies'))->assertOk()->assertSee($request->reference)->assertDontSee('Liberada');
        $this->flushSession();
        $this->actingAs($this->otherBuildingUser)->get(route('portal.emergencies'))->assertOk()->assertDontSee($request->reference);
        $this->flushSession();
        $this->actingAs($this->otherClientUser)->get(route('portal.emergencies'))->assertOk()->assertDontSee($request->reference);
    }

    public function test_history_survives_switching_off_or_downgrading(): void
    {
        $this->submit($this->nonce())->assertOk();
        $this->a['company']->forceFill(['emergency_requests_enabled' => false])->save();
        $this->giveCompanyPlan($this->a['company'], 'inicial');

        $this->assertSame(1, EmergencyRequest::withoutGlobalScopes()->count());
        $this->actingInPanel($this->a['admin']);
        $this->assertTrue(EmergencyRequestResource::shouldRegisterNavigation());
        Livewire::test(ListEmergencyRequests::class)->assertCountTableRecords(1);
    }

    /*
    |--------------------------------------------------------------------------
    | INTERRUPTOR EN «MI EMPRESA»
    |--------------------------------------------------------------------------
    */

    public function test_the_company_switch_is_server_checked(): void
    {
        $this->a['company']->forceFill(['emergency_requests_enabled' => false])->save();

        // Inicial: no se puede encender.
        $this->giveCompanyPlan($this->a['company'], 'inicial');
        $this->actingInPanel($this->a['admin']->fresh());
        Livewire::test(CompanySettings::class)->call('saveEmergencySetting', true);
        $this->assertFalse($this->a['company']->fresh()->emergency_requests_enabled);

        // Profesional: sí; un valor manipulado no se guarda; «Guardar cambios» no lo toca.
        $this->giveCompanyPlan($this->a['company'], 'profesional');
        $this->actingInPanel($this->a['admin']->fresh()); // nueva solicitud: el plan se lee de nuevo
        Livewire::test(CompanySettings::class)->call('saveEmergencySetting', 'true')->call('saveEmergencySetting', true);
        $this->assertTrue($this->a['company']->fresh()->emergency_requests_enabled);
        Livewire::test(CompanySettings::class)->set('data.name', 'Nombre nuevo')->call('save'); // «Guardar cambios» no lo toca
        $this->assertSame(['Nombre nuevo', true], [$this->a['company']->fresh()->name, $this->a['company']->fresh()->emergency_requests_enabled]);
        Livewire::test(CompanySettings::class)->set('data.emergency_requests_enabled', false); // el dueño lo apaga al tocarlo
        $this->assertFalse($this->a['company']->fresh()->emergency_requests_enabled);

        // Un administrativo no puede.
        $this->flushSession();
        $this->actingInPanel($this->staffWo);
        $this->get(CompanySettings::getUrl())->assertForbidden();
    }

    /*
    |--------------------------------------------------------------------------
    | LOGOS, HOJA IMPRIMIBLE Y PÁGINA PÚBLICA
    |--------------------------------------------------------------------------
    */

    public function test_logos_are_public_only_and_the_print_sheet_is_self_contained(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('companies/logos/logo.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='));
        $this->a['company']->forceFill(['logo' => 'companies/logos/logo.png', 'primary_color' => '#123456'])->save();

        $html = $this->publicPage()->assertOk()->getContent();
        $this->assertStringContainsString('/storage/companies/logos/logo.png', $html);
        $this->assertStringContainsString('--qr-accent: #123456', $html);
        $this->assertStringNotContainsString('storage/app', $html);
        $this->assertStringContainsString('images/brand/logo-64.png', $html); // Ascento, recurso local

        // Hoja: QR y logos embebidos (sin imágenes remotas); solo con permiso del área.
        $this->actingInPanel($this->a['admin']);
        $sheet = $this->get(route('public-qr.sheet', ['building' => $this->a['building']->id, 'size' => 'a4']))->assertOk()->getContent();
        $this->assertStringContainsString('<svg', $sheet);
        $this->assertStringContainsString('data:image/png;base64,', $sheet);
        $this->assertStringContainsString('Funciona con <strong>Ascento</strong>', $sheet);
        $this->assertStringContainsString($this->a['company']->name, $sheet);
        $this->assertStringContainsString('¿Quedaste atrapado?', $sheet);
        $this->assertDoesNotMatchRegularExpression('#<img[^>]+src="https?://#', $sheet);
        $this->get(route('public-qr.sheet', ['building' => $this->a['building']->id, 'size' => 'compacta']))->assertOk()->assertSee('data-qr-sheet="compacta"', false);

        $this->flushSession();
        $this->actingInPanel($this->staffOther);
        $this->get(route('public-qr.sheet', ['building' => $this->a['building']->id, 'size' => 'a4']))->assertNotFound();
        $this->flushSession();
        $this->actingInPanel($this->b['admin']);
        $this->get(route('public-qr.sheet', ['building' => $this->a['building']->id, 'size' => 'a4']))->assertNotFound();
    }

    public function test_landing_connects_the_three_actors_without_promising_more_than_exists(): void
    {
        $html = $this->get('/')->assertOk()->getContent();
        $this->assertSame(1, preg_match('#<section[^>]*\bid="conecta".*?</section>#s', $html, $section));
        foreach (['El cuidado del ascensor', 'Empresa de mantenimiento', 'Administración del consorcio', 'Quienes viven o usan el edificio', 'Pedido de ayuda: desde Profesional', 'si la empresa lo activa', 'no es un servicio de emergencias'] as $text) {
            $this->assertStringContainsStringIgnoringCase($text, $section[0], $text);
        }
        foreach (['garantiza', 'garantizado', 'rescate asegurado', 'las 24 horas', 'siempre disponible'] as $promise) {
            $this->assertStringNotContainsStringIgnoringCase($promise, $section[0], $promise);
        }

        $row = collect(SubscriptionPlan::comparisonRows(SubscriptionPlan::offered()))->firstWhere(0, 'Pedido de ayuda por atrapamiento desde el QR');
        $this->assertSame(['inicial' => false, 'profesional' => true, 'empresa' => true], $row[1]);
    }
}
