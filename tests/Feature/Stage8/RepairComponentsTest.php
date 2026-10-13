<?php

namespace Tests\Feature\Stage8;

use App\Filament\Resources\RepairComponents\Pages\CreateRepairComponent;
use App\Filament\Resources\RepairComponents\Pages\EditRepairComponent;
use App\Filament\Resources\RepairComponents\Pages\ListRepairComponents;
use App\Filament\Resources\RepairComponents\Pages\ViewRepairComponent;
use App\Models\ActivityLog;
use App\Models\Building;
use App\Models\Elevator;
use App\Models\RepairComponent;
use App\Models\RepairComponentFile;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\PublicQr\PublicQrService;
use App\Services\Repairs\RepairService;
use App\Services\Staff\StaffManager;
use Carbon\Carbon;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Etapa 8.4: componentes enviados a reparación. Estados claros que solo
 * avanzan por transiciones válidas (nunca «reparado» si solo se envió),
 * edificio, ascensor, orden y responsable de la misma empresa, archivos
 * privados, historial, permisos del área «Reparaciones», bloque opcional
 * del QR y nada entre empresas.
 */
class RepairComponentsTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    private array $a;

    private Elevator $elevator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Storage::fake('local');
        $this->travelTo(Carbon::parse('2026-10-14 09:00'));
        $this->a = $this->makeTenant();
        $this->giveCompanyPlan($this->a['company'], 'empresa');
        $this->elevator = $this->elevatorOf($this->a, 'Equipo R1');
    }

    private function elevatorOf(array $tenant, string $label, ?Building $building = null): Elevator
    {
        $elevator = (new Elevator)->forceFill(['building_id' => ($building ?? $tenant['building'])->id, 'company_id' => $tenant['company']->id, 'label' => $label, 'kind' => 'elevator', 'is_active' => true]);
        $elevator->save();

        return $elevator;
    }

    private function inPanel(User $user): void
    {
        $this->flushSession();
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('ascensores_app'));
    }

    private function staff(array $levels, string $name = 'Ana Oficina'): User
    {
        User::where('company_id', $this->a['company']->id)->where('role', User::ROLE_STAFF)->get()->each->delete();
        $this->actingAs($this->a['admin']);
        $user = app(StaffManager::class)->create($this->a['admin'], ['name' => $name, 'email' => str($name)->slug().'@demo.test', 'password' => 'clave-segura-123'], $levels);
        $this->inPanel($user);

        return $user;
    }

    private function service(): RepairService
    {
        return app(RepairService::class);
    }

    private function base(array $extra = []): array
    {
        return $extra + ['building_id' => $this->a['building']->id, 'elevator_id' => $this->elevator->id, 'component_type' => 'drive', 'failure' => 'El variador no arranca'];
    }

    private function make(array $extra = []): RepairComponent
    {
        $this->inPanel($this->a['admin']);

        return $this->service()->create($this->a['admin'], $this->base($extra));
    }

    private function denied(\Closure $call, int $status): void
    {
        try {
            $call();
            $this->fail("Debía responder {$status}.");
        } catch (HttpException $e) {
            $this->assertSame($status, $e->getStatusCode());
        }
    }

    private function invalid(\Closure $call, string $field): void
    {
        try {
            $call();
            $this->fail("Debía rechazar {$field}.");
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($field, $e->errors());
        }
    }

    public function test_the_owner_registers_and_follows_a_component_through_every_step(): void
    {
        $this->inPanel($this->a['admin']);
        $order = WorkOrder::factory()->create(['building_id' => $this->a['building']->id, 'company_id' => $this->a['company']->id, 'type' => 'claim']);

        Livewire::test(CreateRepairComponent::class)
            ->fillForm([
                'building_id' => $this->a['building']->id, 'elevator_id' => $this->elevator->id, 'work_order_id' => $order->id,
                'component_type' => 'board', 'description' => 'Placa de maniobra', 'brand' => 'Arkel', 'model' => 'Arcode', 'serial_number' => 'SN-1',
                'failure' => 'Se reinicia sola', 'status' => RepairComponent::PENDING, 'provider' => 'Taller Sur', 'follow_up_user_id' => $this->a['technician']->id,
                'files' => [UploadedFile::fake()->image('foto.png', 20, 20)],
            ])
            ->call('create')->assertHasNoFormErrors();

        $c = RepairComponent::sole();
        $this->assertSame([RepairComponent::PENDING, null, $order->id, (int) $this->a['company']->id, 1], [$c->status, $c->sent_at, $c->work_order_id, (int) $c->company_id, $c->files()->count()]);

        // Cada paso con lo que corresponde.
        $flow = [
            [RepairComponent::SENT, ['sent_at' => '2026-10-10', 'expected_return_at' => '2026-10-30']],
            [RepairComponent::DIAGNOSIS, []],
            [RepairComponent::AWAITING_QUOTE, []],
            [RepairComponent::AWAITING_APPROVAL, ['quote_amount' => '150000']],
            [RepairComponent::IN_REPAIR, []],
            [RepairComponent::REPAIRED, ['final_cost' => '148500.50']],
            [RepairComponent::RECEIVED, ['returned_at' => '2026-10-14']],
        ];
        foreach ($flow as [$to, $data]) {
            Livewire::test(ViewRepairComponent::class, ['record' => $c->getRouteKey()])
                ->callAction('changeStatus', ['to' => $to] + $data)->assertHasNoActionErrors();
            $this->assertSame($to, $c->fresh()->status);
        }
        $c = $c->fresh();
        $this->assertSame(['2026-10-10', '150000.00', '148500.50', '2026-10-14'], [$c->sent_at->toDateString(), $c->quote_amount, $c->final_cost, $c->returned_at->toDateString()]);

        // Recibido es final: no hay más cambios de estado.
        Livewire::test(ViewRepairComponent::class, ['record' => $c->getRouteKey()])->assertActionHidden('changeStatus');

        // Historial: alta, cada cambio de estado (de → a) y el archivo.
        $logs = ActivityLog::where('subject_type', 'RepairComponent')->where('subject_id', $c->id)->orderBy('id')->get();
        $this->assertSame('repair_component.created', $logs->first()->action);
        $statusChanges = $logs->where('action', 'repair_component.updated')->map(fn ($l) => $l->changes['status']['to'] ?? null)->filter()->values()->all();
        $this->assertSame(array_column($flow, 0), $statusChanges);
        $this->assertSame(1, $logs->where('action', 'repair_component.file_added')->count());

        $html = $this->get('/admin/reparaciones/'.$c->id)->assertOk()->getContent();
        $this->assertStringContainsString('data-repair-history', $html);
        $this->assertStringContainsString('En reparación → Reparado', $html);
        $this->assertStringContainsString('Taller Sur', $html);
        $this->get('/admin/reparaciones')->assertOk();
    }

    public function test_a_component_that_was_only_sent_is_never_marked_repaired(): void
    {
        $c = $this->make(['status' => RepairComponent::SENT, 'sent_at' => '2026-10-10']);

        // Saltos no permitidos: 422, sin cambios.
        foreach ([RepairComponent::REPAIRED, RepairComponent::RECEIVED, RepairComponent::PENDING, RepairComponent::AWAITING_APPROVAL] as $to) {
            $this->invalid(fn () => $this->service()->changeStatus($this->a['admin'], $c->fresh(), $to, ['returned_at' => '2026-10-14']), 'status');
            $this->assertSame(RepairComponent::SENT, $c->fresh()->status);
        }
        // Tampoco desde el panel: el estado no se elige en «Corregir» y el cambio solo ofrece los permitidos.
        Livewire::test(ViewRepairComponent::class, ['record' => $c->getRouteKey()])
            ->callAction('changeStatus', ['to' => RepairComponent::REPAIRED])->assertHasActionErrors(['to']);
        Livewire::test(EditRepairComponent::class, ['record' => $c->getRouteKey()])
            ->fillForm(['status' => RepairComponent::REPAIRED])->call('save');
        $this->assertSame(RepairComponent::SENT, $c->fresh()->status);

        // Pendiente → reparado o recibido: tampoco.
        $pending = $this->make();
        $this->invalid(fn () => $this->service()->changeStatus($this->a['admin'], $pending, RepairComponent::REPAIRED), 'status');
        $this->invalid(fn () => $this->service()->changeStatus($this->a['admin'], $pending, RepairComponent::SENT), 'sent_at'); // enviarlo pide la fecha

        // Cancelado es final.
        $this->service()->changeStatus($this->a['admin'], $pending, RepairComponent::CANCELLED);
        $this->invalid(fn () => $this->service()->changeStatus($this->a['admin'], $pending->fresh(), RepairComponent::SENT, ['sent_at' => '2026-10-10']), 'status');

        // Un cambio sobre una copia vieja del registro no saltea pasos (se relee bajo bloqueo).
        $stale = $c->fresh();
        $this->service()->changeStatus($this->a['admin'], $c->fresh(), RepairComponent::IN_REPAIR);
        $this->service()->changeStatus($this->a['admin'], $c->fresh(), RepairComponent::REPAIRED);
        $this->invalid(fn () => $this->service()->changeStatus($this->a['admin'], $stale, RepairComponent::IN_REPAIR), 'status');
        $this->assertSame(RepairComponent::REPAIRED, $c->fresh()->status);
    }

    public function test_data_is_validated_against_the_company_and_the_dates(): void
    {
        $b = $this->makeTenant();
        $this->inPanel($this->a['admin']);
        $otherBuilding = Building::factory()->create(['company_id' => $this->a['company']->id, 'client_id' => $this->a['building']->client_id]);
        $otherElevator = $this->elevatorOf($this->a, 'Ascensor X', $otherBuilding);
        $foreignOrder = WorkOrder::factory()->create(['building_id' => $b['building']->id, 'company_id' => $b['company']->id]);
        $otherBuildingOrder = WorkOrder::factory()->create(['building_id' => $otherBuilding->id, 'company_id' => $this->a['company']->id]);
        $this->inPanel($this->a['admin']);
        $create = fn (array $data) => $this->service()->create($this->a['admin'], $data);

        $this->invalid(fn () => $create($this->base(['building_id' => $b['building']->id, 'elevator_id' => null])), 'building_id');
        $this->invalid(fn () => $create($this->base(['elevator_id' => $otherElevator->id])), 'elevator_id');
        $this->invalid(fn () => $create($this->base(['work_order_id' => $foreignOrder->id])), 'work_order_id');
        $this->invalid(fn () => $create($this->base(['work_order_id' => $otherBuildingOrder->id])), 'work_order_id');
        $this->invalid(fn () => $create($this->base(['component_type' => 'nave'])), 'component_type');
        $this->invalid(fn () => $create($this->base(['failure' => '  '])), 'failure');
        $this->invalid(fn () => $create($this->base(['follow_up_user_id' => $b['technician']->id])), 'follow_up_user_id');
        $this->invalid(fn () => $create($this->base(['status' => 'sent'])), 'sent_at');
        $this->invalid(fn () => $create($this->base(['status' => 'sent', 'sent_at' => '2026-10-20'])), 'sent_at'); // futura
        $this->invalid(fn () => $create($this->base(['sent_at' => '2026-10-01'])), 'sent_at'); // pendiente con fecha
        $this->invalid(fn () => $create($this->base(['status' => 'sent', 'sent_at' => '2026-10-10', 'expected_return_at' => '2026-10-01'])), 'expected_return_at');
        $this->invalid(fn () => $create($this->base(['quote_amount' => '-5'])), 'quote_amount');
        $this->invalid(fn () => $create($this->base(['final_cost' => 'mil'])), 'final_cost');
        $this->invalid(fn () => $create($this->base(['returned_at' => '2026-10-10'])), 'returned_at'); // solo al recibirlo

        // Responsable: un administrativo sin «Reparaciones» no; con el área, sí.
        $noArea = $this->staff(['visits' => 'view'], 'Sin Area');
        $this->inPanel($this->a['admin']);
        $this->invalid(fn () => $create($this->base(['follow_up_user_id' => $noArea->id])), 'follow_up_user_id');
        app(StaffManager::class)->setPermissions($this->a['admin'], $noArea, ['repairs' => 'view']);
        $this->inPanel($this->a['admin']);
        $this->assertSame($noArea->id, $create($this->base(['follow_up_user_id' => $noArea->id]))->follow_up_user_id);

        // Recibido: la devolución no puede ser anterior al envío.
        $c = $create($this->base(['status' => 'sent', 'sent_at' => '2026-10-10']));
        $this->service()->changeStatus($this->a['admin'], $c, RepairComponent::IN_REPAIR);
        $this->service()->changeStatus($this->a['admin'], $c->fresh(), RepairComponent::REPAIRED);
        $this->invalid(fn () => $this->service()->changeStatus($this->a['admin'], $c->fresh(), RepairComponent::RECEIVED), 'returned_at');
        $this->invalid(fn () => $this->service()->changeStatus($this->a['admin'], $c->fresh(), RepairComponent::RECEIVED, ['returned_at' => '2026-10-05']), 'returned_at');
        $this->assertSame(RepairComponent::REPAIRED, $c->fresh()->status);

        // Al corregir, no se puede quitar la fecha de envío de uno ya enviado.
        $this->invalid(fn () => $this->service()->update($this->a['admin'], $c->fresh(), ['sent_at' => null]), 'sent_at');
    }

    public function test_staff_need_the_repairs_area_and_manage_to_change_anything(): void
    {
        $c = $this->make(['status' => 'sent', 'sent_at' => '2026-10-10']);
        $this->service()->attach($this->a['admin'], $c, UploadedFile::fake()->createWithContent('a.pdf', "%PDF-1.4\n%%EOF"));
        $file = RepairComponentFile::sole();

        // Sin el área: ni el listado, ni la ficha, ni los archivos.
        $this->staff(['visits' => 'manage'], 'Sin Area');
        $this->get('/admin/reparaciones')->assertForbidden();
        $this->get('/admin/reparaciones/'.$c->id)->assertForbidden();
        $this->get($file->url())->assertNotFound();

        // Con «ver»: consulta, pero no registra, corrige, cambia de estado ni adjunta.
        $viewer = $this->staff(['repairs' => 'view'], 'Solo Ver');
        $this->get('/admin/reparaciones')->assertOk()->assertSee('Variador');
        $this->get('/admin/reparaciones/'.$c->id)->assertOk();
        $this->get($file->url())->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->get('/admin/reparaciones/create')->assertForbidden();
        $this->get('/admin/reparaciones/'.$c->id.'/edit')->assertForbidden();
        Livewire::test(ViewRepairComponent::class, ['record' => $c->getRouteKey()])->assertActionHidden('changeStatus');
        $this->denied(fn () => $this->service()->create($viewer, $this->base()), 403);
        $this->denied(fn () => $this->service()->update($viewer, $c, ['notes' => 'x']), 403);
        $this->denied(fn () => $this->service()->changeStatus($viewer, $c, RepairComponent::IN_REPAIR), 403);
        $this->denied(fn () => $this->service()->attach($viewer, $c, UploadedFile::fake()->createWithContent('b.pdf', "%PDF-1.4\n%%EOF")), 403);
        $this->assertSame(RepairComponent::SENT, $c->fresh()->status);

        // Con «gestionar»: sí. No hay borrado (se cancela).
        $manager = $this->staff(['repairs' => 'manage'], 'Gestiona');
        Livewire::test(ViewRepairComponent::class, ['record' => $c->getRouteKey()])
            ->callAction('changeStatus', ['to' => RepairComponent::IN_REPAIR])->assertHasNoActionErrors();
        $this->assertSame(RepairComponent::IN_REPAIR, $c->fresh()->status);
        Livewire::test(ListRepairComponents::class)->assertTableActionDoesNotExist('delete');

        // Desactivado: nada.
        $this->actingAs($this->a['admin']);
        app(StaffManager::class)->deactivate($this->a['admin'], $manager);
        $this->flushSession();
        $this->actingAs(User::withTrashed()->find($manager->id));
        $this->assertNotSame(200, $this->get('/admin/reparaciones')->getStatusCode());
        $this->denied(fn () => $this->service()->changeStatus(User::withTrashed()->find($manager->id), $c->fresh(), RepairComponent::REPAIRED), 403);

        // Los técnicos no entran al panel; el SuperAdmin, dentro de la empresa elegida.
        $this->flushSession();
        $this->actingAs($this->a['technician']);
        $this->assertNotSame(200, $this->get('/admin/reparaciones')->getStatusCode());
        $this->get($file->url())->assertNotFound();
        auth()->logout();
        $super = User::factory()->superAdmin()->create();
        $this->inPanel($super);
        session(['selected_company_id' => $this->a['company']->id]);
        $this->get('/admin/reparaciones/'.$c->id)->assertOk();
        $this->get($file->url())->assertOk();
    }

    public function test_files_are_private_and_checked_by_content(): void
    {
        $c = $this->make();
        $this->invalid(fn () => $this->service()->attach($this->a['admin'], $c, UploadedFile::fake()->createWithContent('virus.pdf', '<?php echo 1;')), 'files');
        $this->invalid(fn () => $this->service()->attach($this->a['admin'], $c, UploadedFile::fake()->create('grande.pdf', 30000, 'application/pdf')), 'files');
        $this->assertSame([], Storage::disk('local')->allFiles('repairs'));

        $file = $this->service()->attach($this->a['admin'], $c, UploadedFile::fake()->createWithContent('ok.pdf', "%PDF-1.4\n%%EOF"));
        $this->assertStringStartsWith('repairs/'.$this->a['company']->id.'/', $file->path);
        $this->assertStringContainsString('private', $this->get($file->url())->assertOk()->headers->get('Cache-Control'));

        // Una ruta manipulada no se sirve.
        $file->forceFill(['path' => 'elevators/'.$this->a['company']->id.'/doc.pdf'])->save();
        Storage::disk('local')->put('elevators/'.$this->a['company']->id.'/doc.pdf', 'x');
        $this->get($file->url())->assertNotFound();
    }

    public function test_the_qr_shows_components_away_only_when_the_company_publishes_it(): void
    {
        $away = $this->make(['status' => 'sent', 'sent_at' => '2026-10-10', 'provider' => 'Taller Secreto', 'quote_amount' => '987654', 'notes' => 'Nota interna', 'failure' => 'Falla privada']);
        $this->service()->changeStatus($this->a['admin'], $away, RepairComponent::IN_REPAIR);
        $this->make(['component_type' => 'motor']); // pendiente de envío: sigue en el edificio
        $back = $this->make(['component_type' => 'controller', 'status' => 'sent', 'sent_at' => '2026-10-10']);
        $this->service()->changeStatus($this->a['admin'], $back, RepairComponent::CANCELLED);
        $this->make(['component_type' => 'board', 'elevator_id' => null, 'status' => 'sent', 'sent_at' => '2026-10-11']); // del edificio, sin equipo

        $qr = app(PublicQrService::class);
        $page = $qr->generate($this->a['admin'], $this->a['building']);
        auth()->logout();
        $scan = function () use ($page) {
            $this->flushSession();

            return $this->get('/qr/'.$page->fresh()->token)->assertOk()->getContent();
        };

        // Apagado por defecto.
        $this->assertFalse((bool) $page->fresh()->show_repairs);
        $this->assertStringNotContainsString('en reparación', $scan());

        $this->inPanel($this->a['admin']);
        $qr->updateSections($this->a['admin'], $page, ['show_repairs' => true]);
        auth()->logout();
        $html = $scan();
        $this->assertStringContainsString('Variador en reparación: <strong>en reparación</strong>', $html);
        $this->assertStringContainsString('data-qr="building-repairs"', $html);
        $this->assertStringContainsString('Placa electrónica: <strong>enviado</strong>', $html);
        // Ni pendientes ni cancelados; nunca proveedor, montos, falla ni notas.
        $this->assertStringNotContainsString('Motor', $html);
        $this->assertStringNotContainsString('Control:', $html);
        foreach (['Taller Secreto', '987654', '987.654', 'Nota interna', 'Falla privada', 'El variador no arranca'] as $secret) {
            $this->assertStringNotContainsString($secret, $html);
        }

        // Al recibirlo, deja de figurar.
        $this->inPanel($this->a['admin']);
        $this->service()->changeStatus($this->a['admin'], $away->fresh(), RepairComponent::REPAIRED);
        $this->service()->changeStatus($this->a['admin'], $away->fresh(), RepairComponent::RECEIVED, ['returned_at' => '2026-10-14']);
        auth()->logout();
        $this->assertStringNotContainsString('Variador', $scan());
    }

    public function test_repairs_never_cross_companies(): void
    {
        $c = $this->make(['status' => 'sent', 'sent_at' => '2026-10-10', 'provider' => 'Taller de A']);
        $file = $this->service()->attach($this->a['admin'], $c, UploadedFile::fake()->createWithContent('a.pdf', "%PDF-1.4\n%%EOF"));
        auth()->logout();
        $b = $this->makeTenant();
        $this->giveCompanyPlan($b['company'], 'empresa');

        $this->inPanel($b['admin']);
        $this->get('/admin/reparaciones')->assertOk()->assertDontSee('Taller de A');
        $this->get('/admin/reparaciones/'.$c->id)->assertNotFound();
        $this->get('/admin/reparaciones/'.$c->id.'/edit')->assertNotFound();
        $this->get($file->url())->assertNotFound();
        $this->denied(fn () => $this->service()->update($b['admin'], $c, ['notes' => 'x']), 404);
        $this->denied(fn () => $this->service()->changeStatus($b['admin'], $c, RepairComponent::CANCELLED), 404);
        $this->denied(fn () => $this->service()->attach($b['admin'], $c, UploadedFile::fake()->createWithContent('b.pdf', "%PDF-1.4\n%%EOF")), 404);
        // B no registra sobre edificios ni ascensores de A.
        $this->invalid(fn () => $this->service()->create($b['admin'], $this->base()), 'building_id');
        $this->assertSame(0, RepairComponent::withoutGlobalScopes()->where('company_id', $b['company']->id)->count());

        // Lo que B registra es de B, aunque se intente forzar otra empresa.
        $mine = $this->service()->create($b['admin'], ['building_id' => $b['building']->id, 'component_type' => 'motor', 'failure' => 'x', 'company_id' => $this->a['company']->id]);
        $this->assertSame((int) $b['company']->id, (int) $mine->company_id);
        $this->inPanel($this->a['admin']);
        $this->get('/admin/reparaciones/'.$mine->id)->assertNotFound();
    }
}
