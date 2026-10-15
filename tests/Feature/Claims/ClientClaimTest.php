<?php

namespace Tests\Feature\Claims;

use App\Filament\Resources\Claims\ClientClaimResource;
use App\Filament\Resources\Claims\Pages\ViewClientClaim;
use App\Models\Building;
use App\Models\Client;
use App\Models\ClientClaim;
use App\Models\ClientClaimEvent;
use App\Models\ClientClaimPhoto;
use App\Models\Elevator;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\Claims\ClientClaimService;
use App\Services\Notifications\Notifier;
use App\Services\Staff\StaffManager;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Reclamos desde el portal: solo para sus edificios autorizados, llegan a la
 * empresa que corresponde, con historial, sin mostrar notas internas y sin
 * depender de que el aviso funcione.
 */
class ClientClaimTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    private array $a;

    private array $b;

    private Client $client;

    private User $portal;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');
        $this->travelTo(Carbon::parse('2026-10-15 10:00'));
        $this->a = $this->makeTenant();
        $this->b = $this->makeTenant();
        $this->giveCompanyPlan($this->a['company'], 'profesional');
        $this->giveCompanyPlan($this->b['company'], 'profesional');
        $this->client = Client::withoutGlobalScopes()->find($this->a['building']->client_id);
        $this->portal = $this->portalUser($this->client, [$this->a['building']->id], 'consorcio@demo.test');
    }

    private function portalUser(Client $client, array $buildingIds, string $email): User
    {
        $user = User::factory()->create(['email' => $email]);
        $user->forceFill(['role' => User::ROLE_CLIENT, 'company_id' => $client->company_id, 'client_id' => $client->id])->save();
        $user->portalBuildings()->sync($buildingIds);

        return $user;
    }

    private function asPortal(?User $user = null): static
    {
        $this->flushSession();

        return $this->actingAs(($user ?? $this->portal)->fresh());
    }

    private function elevator(Building $building): Elevator
    {
        return Elevator::withoutGlobalScopes()->where('building_id', $building->id)->first()
            ?? Elevator::withoutGlobalScopes()->forceCreate(['company_id' => $building->company_id, 'building_id' => $building->id, 'label' => 'Ascensor 1', 'kind' => 'elevator', 'is_active' => true]);
    }

    private function claim(array $extra = []): ClientClaim
    {
        $this->asPortal()->post(route('portal.claims.store'), $extra + [
            'building_id' => $this->a['building']->id,
            'category' => 'stopped',
            'description' => 'El ascensor quedó detenido en el 3.º piso.',
        ])->assertRedirect();

        return ClientClaim::withoutGlobalScopes()->latest('id')->firstOrFail();
    }

    public function test_an_authorized_client_creates_and_follows_a_claim(): void
    {
        $this->asPortal()->get(route('portal.claims.create'))->assertOk()
            ->assertSee('Usá el botón de alarma o el intercomunicador del ascensor y llamá al 911', false)
            ->assertSee($this->a['building']->name);

        $elevator = $this->elevator($this->a['building']);
        $res = $this->post(route('portal.claims.store'), [
            'building_id' => $this->a['building']->id, 'elevator_id' => $elevator->id, 'category' => 'doors',
            'description' => 'La puerta no cierra.', 'occurred_at' => now()->subHour()->format('Y-m-d\TH:i'), 'contact_phone' => '11 2345-6789',
            'photos' => [UploadedFile::fake()->image('puerta.jpg', 400, 300)],
        ]);
        $claim = ClientClaim::withoutGlobalScopes()->sole();
        $res->assertRedirect(route('portal.claims.show', $claim->id));

        $this->assertSame((int) $this->a['company']->id, (int) $claim->company_id);
        $this->assertSame((int) $this->client->id, (int) $claim->client_id);
        $this->assertSame((int) $elevator->id, (int) $claim->elevator_id);
        $this->assertSame('new', $claim->status);
        $this->assertSame(1, $claim->number);
        $this->assertSame('created', ClientClaimEvent::where('client_claim_id', $claim->id)->sole()->type);
        $photo = ClientClaimPhoto::sole();
        Storage::disk('local')->assertExists($photo->path);
        $this->assertStringStartsWith('client-claims/'.$this->a['company']->id.'/', $photo->path);

        // Registrado (no «leído» ni «atendido»).
        $this->get(route('portal.claims.show', $claim->id))->assertOk()->assertSee('quedó registrado')->assertSee('R-00001')->assertDontSee('leyó');
        $this->get(route('portal.claims.index'))->assertOk()->assertSee('R-00001');
        $this->get(route('portal.claims.photo', [$claim->id, $photo->id]))->assertOk();
    }

    public function test_a_client_cannot_use_other_buildings_elevators_or_companies(): void
    {
        $other = Building::factory()->create(['company_id' => $this->a['company']->id, 'client_id' => $this->client->id]); // del mismo cliente, sin autorizar
        $foreign = $this->b['building'];
        foreach ([$other->id, $foreign->id, 999999] as $buildingId) {
            $this->asPortal()->post(route('portal.claims.store'), ['building_id' => $buildingId, 'category' => 'stopped', 'description' => 'Problema grave'])
                ->assertSessionHasErrors('building_id');
        }
        // Un ascensor de otro edificio.
        $this->post(route('portal.claims.store'), ['building_id' => $this->a['building']->id, 'elevator_id' => $this->elevator($foreign)->id, 'category' => 'stopped', 'description' => 'Problema grave'])
            ->assertSessionHasErrors('elevator_id');
        // company_id / client_id en el pedido se ignoran.
        $this->post(route('portal.claims.store'), ['building_id' => $this->a['building']->id, 'category' => 'other', 'description' => 'Consulta general', 'company_id' => $this->b['company']->id, 'client_id' => 1234])->assertRedirect();
        $claim = ClientClaim::withoutGlobalScopes()->sole();
        $this->assertSame((int) $this->a['company']->id, (int) $claim->company_id);
        $this->assertSame((int) $this->client->id, (int) $claim->client_id);
        // Validaciones de categoría, archivo y cantidad de fotos.
        $this->post(route('portal.claims.store'), ['building_id' => $this->a['building']->id, 'category' => 'inventada', 'description' => 'Algo'])->assertSessionHasErrors('category');
        $this->post(route('portal.claims.store'), ['building_id' => $this->a['building']->id, 'category' => 'other', 'description' => 'Con archivo', 'photos' => [UploadedFile::fake()->create('virus.php', 10, 'application/x-php')]])->assertSessionHasErrors('photos.0');
        $this->post(route('portal.claims.store'), ['building_id' => $this->a['building']->id, 'category' => 'other', 'description' => 'Muchas fotos', 'photos' => array_fill(0, 4, UploadedFile::fake()->image('a.jpg'))])->assertSessionHasErrors('photos');
        $this->assertSame(1, ClientClaim::withoutGlobalScopes()->count());
    }

    public function test_clients_only_see_their_own_claims_and_never_internal_notes(): void
    {
        $claim = $this->claim();
        $neighbor = $this->portalUser($this->client, [$this->a['building']->id], 'vecino@demo.test');
        $this->giveCompanyPlan($this->a['company'], 'profesional');

        app(ClientClaimService::class)->addNote($claim, $this->a['admin'], 'NOTA-INTERNA-SECRETA');
        app(ClientClaimService::class)->reply($claim, $this->a['admin'], 'Mañana pasa un técnico.');
        app(ClientClaimService::class)->changeStatus($claim, $this->a['admin'], 'in_progress');

        $html = $this->asPortal()->get(route('portal.claims.show', $claim->id))->assertOk()->getContent();
        $this->assertStringContainsString('Mañana pasa un técnico.', $html);
        $this->assertStringContainsString('En gestión', $html);
        $this->assertStringNotContainsString('NOTA-INTERNA-SECRETA', $html);

        // Otra persona (aun del mismo cliente) no lo ve, ni sus fotos, ni en su lista.
        $this->asPortal($neighbor)->get(route('portal.claims.show', $claim->id))->assertNotFound();
        $this->get(route('portal.claims.index'))->assertOk()->assertDontSee('R-00001');
        // Avisos al cliente: sin la nota interna.
        $this->assertFalse($this->portal->fresh()->notifications()->get()->contains(fn ($n) => str_contains(json_encode($n->data), 'NOTA-INTERNA')));
    }

    public function test_the_right_company_receives_it_and_other_companies_cannot_see_it(): void
    {
        $claim = $this->claim();
        // Aviso a la empresa correcta (dueño), nunca a la otra.
        $this->assertSame(1, $this->a['admin']->fresh()->notifications()->count());
        $this->assertSame(0, $this->b['admin']->fresh()->notifications()->count());

        $this->actingInPanel($this->a['admin']);
        $this->get(ClientClaimResource::getUrl('view', ['record' => $claim->id]))->assertOk()->assertSee('R-00001');
        $this->get(ClientClaimResource::getUrl())->assertOk()->assertSee('R-00001');

        $this->flushSession();
        $this->actingInPanel($this->b['admin']);
        $this->assertContains($this->get(ClientClaimResource::getUrl('view', ['record' => $claim->id]))->getStatusCode(), [403, 404]);
        $this->get(ClientClaimResource::getUrl())->assertOk()->assertDontSee('R-00001');
        $this->assertSame(0, ClientClaimResource::getEloquentQuery()->count());
        $photo = ClientClaimPhoto::query()->first();
        $this->assertNull($photo); // (sin fotos en este reclamo)

        // Un técnico no gestiona reclamos en el panel.
        $this->flushSession();
        $this->actingAs($this->a['technician']->fresh());
        $this->assertNotSame(200, $this->get(ClientClaimResource::getUrl())->getStatusCode());
    }

    public function test_status_history_assignment_and_a_single_work_order(): void
    {
        $claim = $this->claim();
        $service = app(ClientClaimService::class);
        $this->actingInPanel($this->a['admin']);

        $service->changeStatus($claim, $this->a['admin'], 'reviewing');
        $service->assign($claim, $this->a['admin'], $this->a['admin']->id);
        $order = $service->createWorkOrder($claim, $this->a['admin']);
        $again = $service->createWorkOrder($claim->fresh(), $this->a['admin']);

        $this->assertSame($order->id, $again->id);
        $this->assertSame(1, WorkOrder::withoutGlobalScopes()->where('company_id', $this->a['company']->id)->where('type', 'claim')->count());
        $this->assertSame('claim', $order->type);
        $this->assertSame((int) $claim->building_id, (int) $order->building_id);
        $claim->refresh();
        $this->assertSame($order->id, $claim->work_order_id);
        $this->assertSame((int) $this->a['admin']->id, (int) $claim->assigned_to);
        $this->assertSame(['created', 'status', 'assigned', 'work_order'], ClientClaimEvent::where('client_claim_id', $claim->id)->orderBy('id')->pluck('type')->all());

        // No se asigna a alguien de otra empresa ni se gestiona desde otra empresa.
        try {
            $service->assign($claim, $this->a['admin'], $this->b['admin']->id);
            $this->fail('Asignó a alguien de otra empresa.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
        $this->flushSession();
        $this->actingInPanel($this->b['admin']);
        try {
            $service->changeStatus($claim, $this->b['admin'], 'closed');
            $this->fail('Otra empresa cambió el estado.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
        $this->assertSame('reviewing', $claim->fresh()->status);
    }

    public function test_staff_permissions_view_versus_manage(): void
    {
        $claim = $this->claim();
        $this->giveCompanyPlan($this->a['company'], 'empresa'); // admite varios administrativos
        $this->actingAs($this->a['admin']);
        $viewer = app(StaffManager::class)->create($this->a['admin'], ['name' => 'Ve', 'email' => 've@demo.test', 'password' => 'clave-segura-123'], ['work_orders' => 'view']);
        $none = app(StaffManager::class)->create($this->a['admin'], ['name' => 'Nada', 'email' => 'na@demo.test', 'password' => 'clave-segura-123'], ['tasks' => 'view']);

        $this->flushSession();
        $this->actingInPanel($none->fresh());
        $this->assertContains($this->get(ClientClaimResource::getUrl())->getStatusCode(), [403, 404]);

        $this->flushSession();
        $this->actingInPanel($viewer->fresh());
        $this->get(ClientClaimResource::getUrl('view', ['record' => $claim->id]))->assertOk();
        Livewire::test(ViewClientClaim::class, ['record' => $claim->id])->assertActionHidden('changeStatus');
        try {
            app(ClientClaimService::class)->changeStatus($claim, $viewer->fresh(), 'closed');
            $this->fail('Con «ver» cambió el estado.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
    }

    public function test_a_failing_notification_never_loses_the_claim(): void
    {
        $this->mock(Notifier::class, fn ($mock) => $mock->shouldReceive('sendTo')->andThrow(new \RuntimeException('caído')));
        $claim = $this->claim();
        $this->assertSame('new', $claim->status);
        $this->assertSame(1, ClientClaim::withoutGlobalScopes()->count());
    }

    public function test_no_claims_without_the_portal_in_the_plan_and_photos_are_private(): void
    {
        $claim = $this->claim(['photos' => [UploadedFile::fake()->image('f.jpg')]]);
        $photo = ClientClaimPhoto::sole();

        // La foto: no sin sesión, no para otra empresa, sí para la empresa dueña.
        $this->flushSession();
        auth()->logout();
        $this->get(route('client-claim-photos.show', $photo->id))->assertRedirect();
        $this->actingInPanel($this->b['admin']);
        $this->get(route('client-claim-photos.show', $photo->id))->assertNotFound();
        $this->flushSession();
        $this->actingInPanel($this->a['admin']);
        $this->get(route('client-claim-photos.show', $photo->id))->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');

        // Sin el portal en el plan, no hay reclamos nuevos.
        $this->giveCompanyPlan($this->a['company'], 'inicial');
        $this->assertNotSame(200, $this->asPortal()->get(route('portal.claims.create'))->getStatusCode());
        $this->post(route('portal.claims.store'), ['building_id' => $this->a['building']->id, 'category' => 'other', 'description' => 'Sin plan']);
        $this->assertSame(1, ClientClaim::withoutGlobalScopes()->count());
        $this->assertSame($claim->id, ClientClaim::withoutGlobalScopes()->sole()->id);
    }
}
