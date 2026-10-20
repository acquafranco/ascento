<?php

namespace Tests\Feature\Conecta;

use App\Filament\Pages\CompanySettings;
use App\Filament\Resources\Claims\ClientClaimResource;
use App\Filament\Resources\Claims\Pages\ListClientClaims;
use App\Models\Building;
use App\Models\BuildingPublicPage;
use App\Models\BuildingVisit;
use App\Models\BuildingVisitPlan;
use App\Models\Client;
use App\Models\ClientClaim;
use App\Models\ClientClaimDelivery;
use App\Models\ClientClaimEvent;
use App\Models\ClientClaimPhoto;
use App\Models\DeliveryNote;
use App\Models\Elevator;
use App\Models\ElevatorDocument;
use App\Models\User;
use App\Models\WorkOrder;
use App\Notifications\App\ClientClaimNotification;
use App\Services\Claims\ClientClaimService;
use App\Services\Notifications\Notifier;
use App\Services\PublicQr\PublicQrService;
use App\Services\Staff\StaffManager;
use App\Support\Portal\BuildingTransparency;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Ascento Conecta: «Reportar un problema» desde el QR, pasaporte del ascensor y
 * portal de transparencia. Cubre lo que pide la Fase 7 que no cubren
 * EmergencyRequestTest (retiro del pedido de ayuda), LegalPagesTest (textos)
 * ni ClientClaimTest (reclamos del portal).
 */
class AscentoConectaTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    private array $a;

    private array $b;

    private BuildingPublicPage $page;

    private Elevator $elevator;

    private User $assignedTech;   // técnico asignado al edificio del QR

    private User $otherTech;      // técnico de la misma empresa, sin ese edificio

    private User $staffWo;        // administrativo con «Órdenes de trabajo»

    private User $staffOther;     // administrativo sin ese permiso

    private User $portalUser;     // administración con el edificio autorizado

    private User $portalOther;    // misma administración, otro edificio

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Storage::fake('local');
        $this->travelTo(Carbon::parse('2026-10-20 10:00'));
        $this->b = $this->makeTenant();
        $this->a = $this->makeTenant();
        $this->giveCompanyPlan($this->a['company'], 'empresa');
        $this->giveCompanyPlan($this->b['company'], 'empresa');

        $building = $this->a['building'];
        $this->elevator = Elevator::withoutGlobalScopes()->where('building_id', $building->id)->first()
            ?? Elevator::withoutGlobalScopes()->forceCreate(['company_id' => $building->company_id, 'building_id' => $building->id, 'label' => 'Ascensor 1', 'kind' => 'elevator', 'is_active' => true]);
        $this->assignedTech = $this->a['technician']; // makeTenant lo asigna al edificio
        $this->otherTech = User::factory()->technician()->create(['company_id' => $this->a['company']->id]);

        $client = Client::find($building->client_id);
        $other = Building::factory()->create(['company_id' => $this->a['company']->id, 'client_id' => $client->id]);
        $this->portalUser = $this->portalUser($client, [$building->id], 'admin@consorcio.test');
        $this->portalOther = $this->portalUser($client, [$other->id], 'otro@consorcio.test');

        $this->actingInPanel($this->a['admin']);
        $this->staffWo = app(StaffManager::class)->create($this->a['admin'], ['name' => 'Oficina OT', 'email' => 'ot@empresa.test', 'password' => 'clave-segura-123'], ['work_orders' => 'manage']);
        $this->staffOther = app(StaffManager::class)->create($this->a['admin'], ['name' => 'Oficina Stock', 'email' => 'stock@empresa.test', 'password' => 'clave-segura-123'], ['stock' => 'view']);
        $this->page = app(PublicQrService::class)->generate($this->a['admin'], $building);
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

    /** El comprobante del formulario, como lo entrega la página. */
    private function nonce(?BuildingPublicPage $page = null): string
    {
        $html = $this->get(route('public-qr.report', ['token' => ($page ?? $this->page)->token]))->assertOk()->getContent();
        $this->assertSame(1, preg_match('/name="k" value="([^"]+)"/', $html, $m));
        $this->travel(5)->seconds(); // una persona tarda unos segundos en completarlo

        return html_entity_decode($m[1]);
    }

    private function report(array $data = [], ?string $nonce = null, ?UploadedFile $photo = null, bool $json = false, ?BuildingPublicPage $page = null)
    {
        $page ??= $this->page;
        $payload = ['k' => $nonce ?? $this->nonce($page), 'category' => 'doors', 'description' => 'La puerta del 3.º tarda en cerrar.', 'elevator_id' => '', ...$data];
        if ($photo) {
            $payload['foto'] = $photo;
        }
        $url = route('public-qr.report.store', ['token' => $page->token]);

        return $json ? $this->postJson($url, $payload) : $this->post($url, $payload);
    }

    private function png(): UploadedFile
    {
        return UploadedFile::fake()->image('puerta.png', 40, 40);
    }

    /* ======================== 1, 2, 3, 4: QR → reporte → avisos ======================== */

    public function test_the_qr_identifies_building_elevator_and_company_and_ignores_browser_ids(): void
    {
        $this->get(route('public-qr.show', ['token' => $this->page->token]))->assertOk()
            ->assertSee('Reportar un problema')->assertSee(route('public-qr.report', ['token' => $this->page->token]), false);

        $this->report(['elevator_id' => $this->elevator->id, 'company_id' => $this->b['company']->id, 'building_id' => $this->b['building']->id, 'client_id' => 999])
            ->assertOk()->assertSee('Tu reporte fue registrado');

        $claim = ClientClaim::withoutGlobalScopes()->sole();
        $this->assertSame(
            [(int) $this->a['company']->id, (int) $this->a['building']->id, (int) $this->a['building']->client_id, (int) $this->elevator->id, ClientClaim::ORIGIN_QR, null],
            [(int) $claim->company_id, (int) $claim->building_id, (int) $claim->client_id, (int) $claim->elevator_id, $claim->origin, $claim->created_by],
        );
        $this->assertFalse($claim->shared_with_client);
        $this->assertSame('created', ClientClaimEvent::where('client_claim_id', $claim->id)->value('type') === 'qr_created' ? 'created' : 'x');

        // Un ascensor de otro edificio: no se acepta y no se registra nada.
        $foreign = Elevator::withoutGlobalScopes()->forceCreate(['company_id' => $this->b['company']->id, 'building_id' => $this->b['building']->id, 'label' => 'Ajeno', 'kind' => 'elevator', 'is_active' => true]);
        $this->report(['elevator_id' => $foreign->id, 'description' => 'Otro texto distinto'])->assertStatus(422);
        $this->assertSame(1, ClientClaim::withoutGlobalScopes()->count());
    }

    public function test_the_report_is_saved_before_any_notification_and_survives_when_nobody_is_connected(): void
    {
        // El «canal en tiempo real» falla: el reporte ya está guardado cuando se intenta avisar.
        $seen = [];
        $this->mock(Notifier::class, function ($mock) use (&$seen) {
            $mock->shouldReceive('sendTo')->andReturnUsing(function (User $user) use (&$seen) {
                $seen[] = ClientClaim::withoutGlobalScopes()->count();
                throw new \RuntimeException('websocket caído');
            });
        });

        $this->report()->assertOk()->assertSee('Tu reporte fue registrado');

        $claim = ClientClaim::withoutGlobalScopes()->sole();
        $this->assertNotEmpty($seen);
        $this->assertSame([1], array_values(array_unique($seen))); // siempre después de guardar
        $this->assertTrue(ClientClaimDelivery::where('client_claim_id', $claim->id)->get()->every(fn ($d) => $d->failure === 'error al registrar el aviso'));
        $this->assertStringContainsString('No había nadie habilitado', (string) ClientClaimEvent::where('client_claim_id', $claim->id)->where('type', 'notified')->value('body'));
    }

    public function test_only_people_with_access_are_notified_and_nothing_claims_it_was_received(): void
    {
        $this->report()->assertOk();
        $claim = ClientClaim::withoutGlobalScopes()->sole();

        $notified = DatabaseNotification::where('type', ClientClaimNotification::class)->pluck('notifiable_id')->map(fn ($id) => (int) $id)->sort()->values()->all();
        $expected = collect([$this->a['admin']->id, $this->staffWo->id, $this->assignedTech->id])->map(fn ($id) => (int) $id)->sort()->values()->all();
        $this->assertSame($expected, $notified);

        // Sin conexión en tiempo real (pruebas sin Reverb) el aviso queda guardado igual, sin abrir.
        $rows = ClientClaimDelivery::where('client_claim_id', $claim->id)->with('notification')->get();
        $this->assertCount(3, $rows);
        $this->assertTrue($rows->every(fn ($d) => $d->stateLabel() === 'Aviso registrado en Ascento (sin abrir)'));
        $this->assertSame(['technician'], $rows->where('user_id', $this->assignedTech->id)->pluck('audience')->all());

        // El técnico abre su aviso: recién ahí figura «abierto».
        $this->actingAs($this->assignedTech)->get(route('qr-reports.technician', ['company' => $this->a['company']->slug, 'claim' => $claim->id]))
            ->assertOk()->assertSee('La puerta del 3.º tarda en cerrar.')->assertSee('no es un diagnóstico');
        $this->assertStringStartsWith('Aviso abierto el', $rows->firstWhere('user_id', $this->assignedTech->id)->fresh()->load('notification')->stateLabel());
    }

    /* ======================== 5, 6: aislamiento ======================== */

    public function test_another_company_or_an_unassigned_technician_cannot_see_the_report(): void
    {
        $this->report(['category' => 'damage'], photo: $this->png())->assertOk();
        $claim = ClientClaim::withoutGlobalScopes()->sole();
        $photo = ClientClaimPhoto::where('client_claim_id', $claim->id)->sole();

        $this->actingInPanel($this->b['admin']);
        $this->get(ClientClaimResource::getUrl('view', ['record' => $claim->id]))->assertNotFound();
        Livewire::test(ListClientClaims::class)->assertCanNotSeeTableRecords([$claim]);
        $this->assertNotSame(200, $this->get(route('client-claim-photos.show', $photo->id))->status());

        $this->flushSession();
        $this->actingAs($this->otherTech)->get(route('qr-reports.technician', ['company' => $this->a['company']->slug, 'claim' => $claim->id]))->assertNotFound();
        $this->actingAs($this->otherTech)->get(route('qr-reports.technician.photo', ['company' => $this->a['company']->slug, 'claim' => $claim->id, 'photo' => $photo->id]))->assertNotFound();
        $this->flushSession();
        $this->actingAs($this->b['technician'])->get(route('qr-reports.technician', ['company' => $this->b['company']->slug, 'claim' => $claim->id]))->assertNotFound();

        // Administrativo sin «Órdenes de trabajo»: no entra.
        $this->flushSession();
        $this->actingInPanel($this->staffOther);
        $this->get(ClientClaimResource::getUrl('view', ['record' => $claim->id]))->assertForbidden();

        // Portal: no compartido → no lo ve nadie, ni su foto.
        $this->flushSession();
        $this->actingAs($this->portalUser)->get(route('portal.claims.show', $claim->id))->assertNotFound();
        $this->actingAs($this->portalUser)->get(route('portal.claims.photo', [$claim->id, $photo->id]))->assertNotFound();
    }

    public function test_private_documents_cannot_be_reached_by_changing_the_url(): void
    {
        $this->page->forceFill(['show_documents' => true])->save();
        $make = fn (Elevator $e, bool $public, string $title) => tap((new ElevatorDocument)->forceFill([
            'company_id' => $e->company_id, 'elevator_id' => $e->id, 'type' => 'certificate', 'title' => $title,
            'path' => 'elevators/'.$e->company_id.'/'.$e->id.'/'.$title.'.pdf', 'mime' => 'application/pdf', 'size' => 10, 'original_name' => $title.'.pdf', 'public_on_qr' => $public,
        ]))->save();
        $published = $make($this->elevator, true, 'habilitacion');
        $private = $make($this->elevator, false, 'privado');
        $otherBuilding = Elevator::withoutGlobalScopes()->forceCreate(['company_id' => $this->a['company']->id, 'building_id' => Building::factory()->create(['company_id' => $this->a['company']->id])->id, 'label' => 'Otro', 'kind' => 'elevator', 'is_active' => true]);
        $elsewhere = $make($otherBuilding, true, 'otro-edificio');
        $foreignElevator = Elevator::withoutGlobalScopes()->forceCreate(['company_id' => $this->b['company']->id, 'building_id' => $this->b['building']->id, 'label' => 'B', 'kind' => 'elevator', 'is_active' => true]);
        $foreign = $make($foreignElevator, true, 'otra-empresa');
        foreach ([$published, $private, $elsewhere, $foreign] as $doc) {
            Storage::disk('local')->put($doc->path, '%PDF-1.4');
        }

        $url = fn (ElevatorDocument $d) => route('public-qr.document', ['token' => $this->page->token, 'document' => $d->id]);
        $this->get($url($published))->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->get($url($private))->assertNotFound();
        $this->get($url($elsewhere))->assertNotFound();
        $this->get($url($foreign))->assertNotFound();

        $this->get(route('public-qr.show', ['token' => $this->page->token]))->assertSee('habilitacion')->assertDontSee('privado')->assertDontSee('otro-edificio');

        // Con el bloque apagado, ni el publicado.
        $this->page->forceFill(['show_documents' => false])->save();
        $this->get($url($published))->assertNotFound();
    }

    /* ======================== 7, 8: orden explícita y estados ======================== */

    public function test_reports_never_create_work_orders_alone_and_status_changes_are_recorded(): void
    {
        $this->report()->assertOk();
        $claim = ClientClaim::withoutGlobalScopes()->sole();
        $this->assertSame(0, WorkOrder::withoutGlobalScopes()->count());

        $service = app(ClientClaimService::class);
        $this->actingInPanel($this->a['admin']);
        $service->changeStatus($claim, $this->a['admin'], 'reviewing');

        // Resolver o cerrar sin decir en qué se basa: no.
        try {
            $service->changeStatus($claim, $this->a['admin'], 'resolved');
            $this->fail('Se resolvió sin motivo.');
        } catch (ValidationException) {
        }
        // «Asociado a una orden» no se elige a mano.
        try {
            $service->changeStatus($claim, $this->a['admin'], ClientClaim::LINKED);
            $this->fail('Se asoció sin orden.');
        } catch (ValidationException) {
        }

        $order = $service->createWorkOrder($claim->fresh(), $this->a['admin']);
        $this->assertSame(1, WorkOrder::withoutGlobalScopes()->count());
        $this->assertSame(['claim', ClientClaim::LINKED], [$order->type, $claim->fresh()->status]);
        $this->assertStringStartsWith('Reporte del QR R-', $order->notes);

        $service->changeStatus($claim->fresh(), $this->a['admin'], 'resolved', 'Remito 000123: se ajustó la puerta.');
        $events = ClientClaimEvent::where('client_claim_id', $claim->id)->where('type', 'status')->orderBy('id')->get();
        $this->assertSame([['new', 'reviewing'], ['reviewing', 'linked'], ['linked', 'resolved']], $events->map(fn ($e) => [$e->from, $e->to])->all());
        $this->assertSame([(int) $this->a['admin']->id, 'Remito 000123: se ajustó la puerta.'], [(int) $events->last()->user_id, $events->last()->body]);
        $this->assertNotNull($events->last()->created_at);
    }

    /* ======================== 9, 10: abuso y fotos ======================== */

    public function test_rate_limits_duplicates_bots_and_expired_pages(): void
    {
        // Doble envío del mismo formulario: el mismo reporte.
        $nonce = $this->nonce();
        $this->report(nonce: $nonce)->assertOk()->assertSee('Tu reporte fue registrado');
        $this->report(nonce: $nonce)->assertOk()->assertSee('Este reporte ya estaba registrado');
        // El mismo texto otra vez en pocos minutos: tampoco se duplica.
        $this->report()->assertOk()->assertSee('Este reporte ya estaba registrado');
        $this->assertSame(1, ClientClaim::withoutGlobalScopes()->count());

        // Robot: campo trampa lleno, o envío instantáneo, o comprobante falso: nada.
        $this->travel(61)->seconds(); // (el límite por IP es aparte: ver test_the_ip_rate_limit)
        $this->report(['sitio_web' => 'http://spam', 'description' => 'Texto robot uno'])->assertStatus(422);
        $html = $this->get(route('public-qr.report', ['token' => $this->page->token]))->getContent();
        preg_match('/name="k" value="([^"]+)"/', $html, $m);
        $this->report(['description' => 'Texto instantáneo'], nonce: html_entity_decode($m[1]))->assertStatus(419)->assertSee('No pudimos registrar el reporte');
        $this->report(['description' => 'Texto falso'], nonce: 'abc.123.def')->assertStatus(419);
        $this->assertSame(1, ClientClaim::withoutGlobalScopes()->count());

        // Tope por edificio (abuso desde muchas conexiones): se dice que no se pudo, sin confirmar nada.
        $this->travel(61)->seconds();
        foreach (range(2, ClientClaimService::QR_MAX_PER_BUILDING_HOUR) as $i) {
            app(ClientClaimService::class)->createFromQr($this->page, ['category' => 'noise', 'description' => "Ruido número {$i}"], null, hash('sha256', "k{$i}"), '10.0.0.'.$i);
        }
        $this->report(['description' => 'Uno más'])->assertStatus(429)->assertSee('No pudimos registrar el reporte ahora')->assertDontSee('fue registrado');
        $this->assertSame(ClientClaimService::QR_MAX_PER_BUILDING_HOUR, ClientClaim::withoutGlobalScopes()->count());
    }

    public function test_the_ip_rate_limit_answers_without_registering(): void
    {
        $statuses = [];
        foreach (range(1, 6) as $i) {
            $statuses[] = $this->post(route('public-qr.report.store', ['token' => $this->page->token]), ['k' => 'x', 'category' => 'doors', 'description' => 'Prueba '.$i])->status();
        }
        $this->assertSame(429, end($statuses));
        $this->assertSame(0, ClientClaim::withoutGlobalScopes()->count());
    }

    public function test_photos_are_validated_and_stored_privately(): void
    {
        // No es una imagen aunque se llame .jpg: el tipo se toma del contenido (como en producción).
        $tmp = tempnam(sys_get_temp_dir(), 'qr');
        file_put_contents($tmp, '<?php echo 1;');
        $fake = new UploadedFile($tmp, 'foto.jpg', 'image/jpeg', null, true);
        $this->report(['description' => 'Con archivo falso'], photo: $fake)->assertStatus(422)->assertSee('La foto tiene que ser una imagen');
        // Demasiado grande: no.
        $this->report(['description' => 'Con foto pesada'], photo: UploadedFile::fake()->image('grande.jpg')->size(6000))->assertStatus(422)->assertSee('hasta 5 MB');
        $this->assertSame(0, ClientClaim::withoutGlobalScopes()->count());

        $this->report(['description' => 'Con foto válida'], photo: $this->png())->assertOk();
        $claim = ClientClaim::withoutGlobalScopes()->sole();
        $photo = ClientClaimPhoto::where('client_claim_id', $claim->id)->sole();
        $this->assertMatchesRegularExpression('#^client-claims/'.$claim->company_id.'/'.$claim->id.'/[A-Za-z0-9]{40}\.png$#', $photo->path);
        Storage::disk('local')->assertExists($photo->path);
        $this->assertSame('image/png', $photo->mime);

        // Solo con sesión y permiso (no hay enlace público a la foto).
        $this->get(route('client-claim-photos.show', $photo->id))->assertRedirect();
        $this->actingInPanel($this->a['admin']);
        $this->get(route('client-claim-photos.show', $photo->id))->assertOk();
    }

    /* ======================== 11, 16: pasaporte y textos ======================== */

    public function test_the_public_passport_shows_only_authorized_data_and_no_rescue_promises(): void
    {
        $this->page->forceFill(['show_service_history' => true, 'show_incidents' => true, 'show_next_visit' => true])->save();
        DeliveryNote::factory()->create(['company_id' => $this->a['company']->id, 'building_id' => $this->a['building']->id, 'user_id' => $this->assignedTech->id,
            'assignment_type' => 'maintenance', 'performed' => true, 'created_at' => '2026-10-03 10:00', 'description' => 'Detalle interno', 'signature_name' => 'Firma Privada']);
        $this->report(['description' => 'Descripción privada del vecino'])->assertOk();
        $hidden = ClientClaim::withoutGlobalScopes()->sole();
        $this->report(['category' => 'lighting', 'description' => 'Otra descripción privada'])->assertOk();
        $shown = ClientClaim::withoutGlobalScopes()->latest('id')->first();
        $this->actingInPanel($this->a['admin']);
        app(ClientClaimService::class)->publishOnQr($shown, $this->a['admin'], true);
        auth()->logout();

        $html = $this->get(route('public-qr.show', ['token' => $this->page->token]))->assertOk()->getContent();
        $this->assertStringContainsString('Historial de servicios', $html);
        $this->assertStringContainsString('03/10/2026 · Mantenimiento', $html);
        $this->assertStringContainsString('Problemas de iluminación', $html);
        $this->assertStringContainsString('Recibido', $html);
        $this->assertStringContainsString('no garantiza que el ascensor esté en condiciones de funcionamiento', $html);
        foreach (['Detalle interno', 'Firma Privada', $this->assignedTech->name, 'Descripción privada del vecino', 'Otra descripción privada', 'Estoy atrapado', 'pedir ayuda', 'rescate garantizado'] as $private) {
            $this->assertStringNotContainsString($private, $html, $private);
        }
        // Sin frecuencia cargada, no se inventa una visita prevista.
        $this->assertStringNotContainsString('previsto, todavía sin registrar', $html);
        BuildingVisitPlan::withoutGlobalScopes()->create(['building_id' => $this->a['building']->id, 'type' => 'maintenance', 'visits_per_month' => 1, 'every_months' => 1, 'starts_on' => '2026-01-01']);
        $this->get(route('public-qr.show', ['token' => $this->page->token]))->assertSee('Mantenimiento de Octubre 2026: previsto, todavía sin registrar');
        $this->assertFalse($hidden->fresh()->public_on_qr);

        // Las pantallas del reporte nunca prometen asistencia.
        $form = $this->get(route('public-qr.report', ['token' => $this->page->token]))->getContent();
        $this->assertStringContainsString('no es un servicio de emergencias', $form);
        $this->assertStringContainsString('911', $form);
        $result = $this->report(['description' => 'Texto para ver el resultado'])->getContent();
        $this->assertStringContainsString('no garantiza una respuesta inmediata', $result);
        foreach (['va a ir', 'técnico en camino', 'ya recibió', 'se va a resolver', 'resuelto'] as $promise) {
            $this->assertStringNotContainsStringIgnoringCase($promise, $result, $promise);
        }
    }

    public function test_the_company_can_switch_reports_off_and_plans_without_it_do_not_offer_it(): void
    {
        $this->actingInPanel($this->a['admin']->fresh());
        Livewire::test(CompanySettings::class)->set('data.qr_reports_enabled', false);
        $this->assertFalse($this->a['company']->fresh()->qr_reports_enabled);
        auth()->logout();

        $this->get(route('public-qr.show', ['token' => $this->page->token]))->assertDontSee('Reportar un problema');
        $this->get(route('public-qr.report', ['token' => $this->page->token]))->assertNotFound();
        $this->post(route('public-qr.report.store', ['token' => $this->page->token]), ['k' => 'x', 'category' => 'doors', 'description' => 'Algo raro'])->assertNotFound();

        $this->a['company']->forceFill(['qr_reports_enabled' => true])->save();
        $this->giveCompanyPlan($this->a['company'], 'inicial');
        $this->get(route('public-qr.show', ['token' => $this->page->token]))->assertOk()->assertDontSee('Reportar un problema');
        $this->assertSame(0, ClientClaim::withoutGlobalScopes()->count());
    }

    /* ======================== 12, 13: portal y resumen ======================== */

    public function test_the_portal_shows_only_shared_reports_of_authorized_buildings(): void
    {
        $this->report(photo: $this->png())->assertOk();
        $claim = ClientClaim::withoutGlobalScopes()->sole();

        $this->actingAs($this->portalUser)->get(route('portal.claims.index'))->assertOk()->assertDontSee($claim->reference());

        $this->actingInPanel($this->a['admin']);
        app(ClientClaimService::class)->shareWithClient($claim, $this->a['admin'], true);
        auth()->logout();
        $this->flushSession();

        $this->actingAs($this->portalUser)->get(route('portal.claims.index'))->assertOk()->assertSee($claim->reference())->assertSee('Reportado desde el QR');
        $this->actingAs($this->portalUser)->get(route('portal.claims.show', $claim->id))->assertOk()
            ->assertSee('La puerta del 3.º tarda en cerrar.')->assertDontSee('/fotos/', false)->assertDontSee('client-claims/', false);
        $this->actingAs($this->portalUser)->get(route('portal.building', $this->a['building']))->assertOk()->assertSee($claim->reference());
        // La administración recibió el aviso de que se compartió.
        $this->assertSame(1, DatabaseNotification::where('notifiable_id', $this->portalUser->id)->where('type', ClientClaimNotification::class)->count());

        // La misma administración sin ese edificio: no.
        $this->flushSession();
        $this->actingAs($this->portalOther)->get(route('portal.claims.index'))->assertOk()->assertDontSee($claim->reference());
        $this->actingAs($this->portalOther)->get(route('portal.claims.show', $claim->id))->assertNotFound();
        $this->actingAs($this->portalOther)->get(route('portal.building-summary', $this->a['building']))->assertNotFound();
    }

    public function test_the_monthly_summary_is_computed_from_real_records(): void
    {
        $b = $this->a['building'];
        $note = fn (array $attrs) => DeliveryNote::factory()->create(['company_id' => $this->a['company']->id, 'building_id' => $b->id, 'user_id' => $this->assignedTech->id, ...$attrs]);
        // Octubre: un mantenimiento realizado con remito compartido, una inspección no realizada (sin compartir).
        $v1 = BuildingVisit::factory()->create(['building_id' => $b->id, 'user_id' => $this->assignedTech->id, 'assignment_type' => 'maintenance', 'month' => 10, 'year' => 2026, 'visited_at' => '2026-10-05 10:00']);
        $note(['building_visit_id' => $v1->id, 'assignment_type' => 'maintenance', 'performed' => true, 'created_at' => '2026-10-05 10:30'])->forceFill(['shared_with_client' => true])->save();
        $v2 = BuildingVisit::factory()->create(['building_id' => $b->id, 'user_id' => $this->assignedTech->id, 'assignment_type' => 'inspection', 'month' => 10, 'year' => 2026, 'visited_at' => '2026-10-06 10:00']);
        $note(['building_visit_id' => $v2->id, 'assignment_type' => 'inspection', 'performed' => false, 'created_at' => '2026-10-06 10:30']);
        // Septiembre: no cuenta en octubre.
        BuildingVisit::factory()->create(['building_id' => $b->id, 'user_id' => $this->assignedTech->id, 'assignment_type' => 'maintenance', 'month' => 9, 'year' => 2026, 'visited_at' => '2026-09-05 10:00']);
        // Un reporte compartido y otro no.
        $this->report()->assertOk();
        $this->report(['category' => 'noise', 'description' => 'Ruido fuerte al subir'])->assertOk();
        $this->actingInPanel($this->a['admin']);
        app(ClientClaimService::class)->shareWithClient(ClientClaim::withoutGlobalScopes()->orderBy('id')->first(), $this->a['admin'], true);
        auth()->logout();

        $this->actingAs($this->portalUser);
        $request = request();
        $request->setUserResolver(fn () => $this->portalUser);
        $s = BuildingTransparency::summary($request, (int) $this->a['company']->id, $b, 10, 2026);

        $this->assertSame(['total' => 1, 'done' => 1, 'not_done' => 0], $s['visits']['maintenance']);
        $this->assertSame(['total' => 1, 'done' => 0, 'not_done' => 1], $s['visits']['inspection']);
        $this->assertSame(1, $s['documents']['delivery_note']);       // solo el compartido
        $this->assertSame(1, $s['incidents']['total']);                // solo el compartido
        $this->assertSame(['Recibido' => 1], $s['incidents']['by_status']);
        $this->assertSame([], $s['quotes']);
        $this->assertSame([], $s['period']);                           // sin frecuencia cargada: nada previsto
        $this->assertTrue($s['partial']);

        $this->get(route('portal.building-summary', ['building' => $b, 'mes' => '2026-10']))->assertOk()
            ->assertSee('Resumen de octubre 2026')->assertSee('El mes todavía no terminó')
            ->assertSee('No es un certificado de cumplimiento normativo ni una evaluación técnica de seguridad')
            ->assertSee('Sin presupuestos compartidos en el período');
        $this->get(route('portal.building-summary', ['building' => $b, 'mes' => '2026-09']))->assertOk()->assertSee('Sin reclamos ni incidencias compartidas en el período');
        $this->get(route('portal.building-summary', ['building' => $b, 'mes' => '2030-01']))->assertNotFound();
        $this->get(route('portal.building-summary', ['building' => $b, 'mes' => 'nada']))->assertRedirect();
    }

    /* ======================== 14, 18: conectividad y confirmaciones ======================== */

    public function test_connectivity_errors_never_show_a_false_confirmation(): void
    {
        // El envío con JavaScript recibe el estado real; si la red falla, la página dice que no pudo confirmar.
        $review = $this->post(route('public-qr.report.review', ['token' => $this->page->token]), ['k' => $this->nonce(), 'category' => 'doors', 'description' => 'La puerta no cierra'])
            ->assertOk()->assertSee('Revisá tu reporte antes de enviarlo')->assertSee('Todavía no se envió nada')->getContent();
        $this->assertStringContainsString('No pudimos confirmar que tu reporte se haya registrado', $review);
        $this->assertSame(0, ClientClaim::withoutGlobalScopes()->count()); // el resumen no guarda nada

        // Si falta algo, el formulario dice qué (y no avanza).
        $this->post(route('public-qr.report.review', ['token' => $this->page->token]), ['k' => $this->nonce(), 'description' => 'Sin categoría'])
            ->assertStatus(422)->assertSee('Elegí qué notaste.')->assertSee('Sin categoría');

        $json = $this->report(json: true)->assertOk()->json();
        $this->assertSame('registered', $json['state']);
        $this->assertStringContainsString('Tu reporte fue registrado', $json['html']);

        $invalid = $this->report(['category' => 'nada'], json: true)->assertStatus(422)->json();
        $this->assertSame('invalid', $invalid['state']);
        $this->assertArrayNotHasKey('html', $invalid);
        $this->assertSame(1, ClientClaim::withoutGlobalScopes()->count());
    }
}
