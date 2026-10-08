<?php

namespace Tests\Feature\Audit;

use App\Filament\Resources\Buildings\BuildingResource;
use App\Filament\Resources\Buildings\Pages\ListBuildings;
use App\Filament\Resources\Clients\ClientResource;
use App\Filament\Resources\DeliveryNotes\DeliveryNoteResource;
use App\Filament\Resources\Inspections\InspectionResource;
use App\Filament\Resources\Maintenances\MaintenanceResource;
use App\Filament\Resources\MaintenanceServices\MaintenanceServiceResource;
use App\Filament\Resources\Quotes\Pages\ListQuotes;
use App\Filament\Resources\Quotes\QuoteResource;
use App\Filament\Resources\Receivables\ReceivableResource;
use App\Filament\Resources\Reports\ReportResource;
use App\Filament\Resources\StockItems\StockItemResource;
use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\UserResource;
use App\Filament\Resources\WorkOrders\WorkOrderResource;
use App\Filament\Widgets\AdminStats;
use App\Models\Building;
use App\Models\BuildingVisit;
use App\Models\Client;
use App\Models\Company;
use App\Models\DeliveryNote;
use App\Models\MaintenanceService;
use App\Models\Quote;
use App\Models\Receivable;
use App\Models\Report;
use App\Models\StockItem;
use App\Models\StockMovement;
use App\Models\Subscription;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderMaterial;
use App\Services\Billing\ReceivableService;
use App\Services\Stock\StockService;
use App\Support\PhoneNumber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Auditoría pre-producción: dos empresas completas (admin + dos técnicos,
 * clientes, edificios, órdenes, mantenimientos, inspecciones, remitos,
 * reportes, presupuestos, stock, servicios) y ataques deliberados entre
 * empresas y entre técnicos de la misma empresa.
 */
class PreProductionAuditTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, mixed> */
    private array $a;

    /** @var array<string, mixed> */
    private array $b;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Storage::fake('local');

        $this->a = $this->company('Ascensores Alfa');
        $this->b = $this->company('Ascensores Beta');
    }

    /** Empresa completa con datos de cada entidad. */
    private function company(string $name): array
    {
        $company = Company::factory()->create(['name' => $name]);
        $admin = User::factory()->admin()->create(['company_id' => $company->id]);
        $t1 = User::factory()->technician()->create(['company_id' => $company->id]);
        $t2 = User::factory()->technician()->create(['company_id' => $company->id]);

        $client = Client::factory()->create(['company_id' => $company->id]);
        // b1: mantenimiento de t1. b2: mantenimiento de t2 + inspección de t2.
        $b1 = Building::factory()->create(['company_id' => $company->id, 'client_id' => $client->id, 'elevator_count' => 2, 'freight_elevator_count' => 0]);
        $b2 = Building::factory()->create(['company_id' => $company->id, 'client_id' => $client->id, 'elevator_count' => 3, 'freight_elevator_count' => 1]);
        $b1->users()->attach($t1->id, ['type' => 'maintenance']);
        $b2->users()->attach($t2->id, ['type' => 'maintenance']);
        $b2->users()->attach($t2->id, ['type' => 'inspection']);

        $wo1 = WorkOrder::factory()->create(['company_id' => $company->id, 'building_id' => $b1->id, 'status' => 'in_progress']);
        $wo1->users()->attach($t1->id);
        $wo1->participants()->attach($t1->id, ['role' => 'participant']);
        $wo2 = WorkOrder::factory()->create(['company_id' => $company->id, 'building_id' => $b2->id, 'status' => 'pending']);
        $wo2->users()->attach($t2->id);

        $visit1 = BuildingVisit::factory()->create(['building_id' => $b1->id, 'user_id' => $t1->id, 'assignment_type' => 'maintenance']);
        $visit1->participants()->attach($t1->id, ['role' => 'creator']);
        $note1 = DeliveryNote::factory()->create(['building_id' => $b1->id, 'user_id' => $t1->id, 'building_visit_id' => $visit1->id, 'number' => str_pad((string) random_int(1, 99999), 8, '0', STR_PAD_LEFT)]);
        $inspection = DeliveryNote::factory()->create(['building_id' => $b2->id, 'user_id' => $t2->id, 'assignment_type' => 'inspection', 'number' => str_pad((string) random_int(100000, 199999), 8, '0', STR_PAD_LEFT)]);

        Storage::disk('local')->put("reports/{$company->id}/foto-{$t1->id}.jpg", 'jpg');
        $report1 = Report::factory()->create(['building_id' => $b1->id, 'user_id' => $t1->id, 'photo' => "reports/{$company->id}/foto-{$t1->id}.jpg"]);

        $quote = Quote::factory()->create(['company_id' => $company->id, 'building_id' => $b1->id, 'client_id' => $client->id, 'created_by' => $admin->id]);

        $this->actingAs($admin);
        $item = StockItem::create(['name' => 'Contactor '.$name, 'unit' => 'unidad', 'cost' => 100, 'min_stock' => 1, 'is_active' => true]);
        app(StockService::class)->receive($item, 10, $admin, 'Stock inicial');
        $service = MaintenanceService::create(['client_id' => $client->id, 'building_id' => $b1->id, 'description' => 'Abono', 'amount' => 1000, 'frequency' => 'monthly', 'start_date' => now()->startOfMonth(), 'payment_due_day' => 10, 'status' => 'active']);
        auth()->logout();

        return compact('company', 'admin', 't1', 't2', 'client', 'b1', 'b2', 'wo1', 'wo2', 'visit1', 'note1', 'inspection', 'report1', 'quote', 'item', 'service') + [
            'receivable' => $service->receivables()->withoutGlobalScopes()->first(),
            'slug' => $company->slug,
        ];
    }

    private function signature(): string
    {
        return 'data:image/png;base64,'.str_repeat('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJ', 3);
    }

    private function remito(array $overrides = []): array
    {
        return [
            'building_id' => $this->a['b1']->id,
            'description' => 'Mantenimiento mensual',
            'month' => now()->month,
            'year' => now()->year,
            'elevator_quantity' => 2,
            'freight_elevator_quantity' => 0,
            'assignment_type' => 'maintenance',
            'signature_name' => 'Técnico',
            'signature' => $this->signature(),
            ...$overrides,
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | ENTRE EMPRESAS: PANEL DEL ADMIN
    |--------------------------------------------------------------------------
    */

    /** Remito de B con un número que A no tiene (la numeración es por empresa). */
    private function noteOnlyInB(string $type = 'maintenance'): DeliveryNote
    {
        DeliveryNote::factory()->count(3)->create(['building_id' => $this->b['b1']->id, 'assignment_type' => $type]);
        $note = DeliveryNote::withoutGlobalScopes()->where('company_id', $this->b['company']->id)->latest('id')->first();
        $this->assertFalse(DeliveryNote::withoutGlobalScopes()->where('company_id', $this->a['company']->id)->where('number', $note->number)->exists());

        return $note;
    }

    public function test_admin_cannot_open_any_record_of_another_company_in_the_panel(): void
    {
        $b = $this->b;
        $b['note1'] = $this->noteOnlyInB();
        $b['inspection'] = $this->noteOnlyInB('inspection');
        $this->actingAs($this->a['admin']);

        $urls = [
            ClientResource::getUrl('edit', ['record' => $b['client']]),
            BuildingResource::getUrl('edit', ['record' => $b['b1']]),
            WorkOrderResource::getUrl('edit', ['record' => $b['wo1']]),
            DeliveryNoteResource::getUrl('view', ['record' => $b['note1']]),
            MaintenanceResource::getUrl('view', ['record' => $b['note1']]),
            MaintenanceResource::getUrl('edit', ['record' => $b['note1']]),
            InspectionResource::getUrl('view', ['record' => $b['inspection']]),
            InspectionResource::getUrl('edit', ['record' => $b['inspection']]),
            ReportResource::getUrl('view', ['record' => $b['report1']]),
            ReportResource::getUrl('edit', ['record' => $b['report1']]),
            QuoteResource::getUrl('view', ['record' => $b['quote']]),
            QuoteResource::getUrl('edit', ['record' => $b['quote']]),
            UserResource::getUrl('view', ['record' => $b['t1']]),
            UserResource::getUrl('edit', ['record' => $b['t1']]),
            UserResource::getUrl('edit', ['record' => $b['admin']]),
            StockItemResource::getUrl('edit', ['record' => $b['item']]),
            MaintenanceServiceResource::getUrl('edit', ['record' => $b['service']]),
            ReceivableResource::getUrl('view', ['record' => $b['receivable']]),
        ];

        foreach ($urls as $url) {
            $this->assertSame(404, $this->get($url)->status(), $url);
        }

        // Mismo número en las dos empresas: se abre SIEMPRE el propio.
        $this->get(DeliveryNoteResource::getUrl('view', ['record' => $this->a['note1']]))->assertOk()
            ->assertSee($this->a['b1']->name)->assertDontSee($this->b['b1']->name);

        // Control positivo: lo propio abre.
        $this->get(WorkOrderResource::getUrl('edit', ['record' => $this->a['wo1']]))->assertOk();
        $this->get(MaintenanceResource::getUrl('view', ['record' => $this->a['note1']]))->assertOk();
        $this->get(InspectionResource::getUrl('view', ['record' => $this->a['inspection']]))->assertOk();
    }

    public function test_assigning_a_technician_of_another_company_to_a_building_is_rejected(): void
    {
        $this->actingAs($this->a['admin']);

        Livewire::test(ListBuildings::class)
            ->callTableAction('assignTechnician', $this->a['b1'], data: ['user_ids' => [$this->b['t1']->id], 'type' => 'maintenance'])
            ->assertHasTableActionErrors(['user_ids.0']);

        $this->assertFalse($this->a['b1']->users()->where('users.id', $this->b['t1']->id)->exists());
    }

    public function test_dashboard_counters_only_count_the_own_company(): void
    {
        // B tiene muchísimos más datos: si algún contador fuera global, se notaría.
        WorkOrder::factory()->count(7)->create(['company_id' => $this->b['company']->id, 'building_id' => $this->b['b1']->id, 'status' => 'pending']);
        Building::factory()->count(5)->create(['company_id' => $this->b['company']->id]);

        $this->actingAs($this->a['admin']);

        $stats = collect((fn () => $this->getStats())->call(new AdminStats))
            ->mapWithKeys(fn ($stat) => [(string) $stat->getLabel() => (string) $stat->getValue()]);

        $this->assertSame('1', $stats->first(fn ($v, $k) => str_contains($k, 'pendiente') && str_contains(mb_strtolower($k), 'trabajo')) ?? $stats->values()->first());
        $this->assertSame('2', $stats['Edificios']);
    }

    public function test_public_links_never_cross_companies(): void
    {
        $quoteA = $this->a['quote']->fresh();
        $noteA = $this->a['note1']->fresh();

        $this->get("/{$this->a['slug']}/quote/{$quoteA->public_token}")->assertOk();
        $this->get("/{$this->b['slug']}/quote/{$quoteA->public_token}")->assertNotFound();

        if ($noteA->public_token) {
            $this->get("/{$this->b['slug']}/public/delivery-notes/{$noteA->public_token}")->assertNotFound();
        }
    }

    /*
    |--------------------------------------------------------------------------
    | ENTRE EMPRESAS: APP DEL TÉCNICO
    |--------------------------------------------------------------------------
    */

    public function test_technician_cannot_reach_another_company_through_any_url(): void
    {
        $b = $this->b;
        $b['note1'] = $this->noteOnlyInB();
        $this->actingAs($this->a['t1']);
        $own = $this->a['slug'];

        // Con el slug propio e IDs ajenos: 404.
        foreach ([
            "/{$own}/work-orders/{$b['wo1']->id}",
            "/{$own}/reports/{$b['report1']->id}",
            "/{$own}/delivery-notes/{$b['note1']->number}",
            "/{$own}/delivery-notes/create/building/{$b['b1']->id}",
            "/{$own}/delivery-notes/create/work-order/{$b['wo1']->id}",
            "/files/reports/{$b['report1']->id}/photo",
        ] as $url) {
            $this->assertContains($this->get($url)->status(), [403, 404], $url);
        }

        // Con el slug de la otra empresa: vuelve a su pantalla, nunca ve datos.
        $this->assertContains($this->get("/{$b['slug']}/work-orders/{$b['wo1']->id}")->status(), [302, 404]);
        $this->assertContains($this->post("/{$b['slug']}/work-orders/{$b['wo1']->id}/start")->status(), [403, 404]);

        // Remito con edificio / orden / participante de la otra empresa.
        $this->post("/{$own}/delivery-notes/store", $this->remito(['building_id' => $b['b1']->id]))->assertNotFound();
        $this->post("/{$own}/delivery-notes/store", $this->remito(['work_order_id' => $b['wo1']->id, 'assignment_type' => 'work_order']))->assertNotFound();
        $this->post("/{$own}/delivery-notes/store", $this->remito(['participants' => [$b['t1']->id]]))->assertSessionHasErrors('participants.0');

        $this->assertSame('in_progress', $b['wo1']->fresh()->status);
        $this->assertSame(0, DeliveryNote::withoutGlobalScopes()->where('company_id', $this->a['company']->id)->where('building_id', $b['b1']->id)->count());
    }

    /*
    |--------------------------------------------------------------------------
    | DENTRO DE LA EMPRESA: TÉCNICO CONTRA TÉCNICO
    |--------------------------------------------------------------------------
    */

    public function test_a_technician_cannot_see_or_operate_another_technicians_work(): void
    {
        $a = $this->a;
        $this->actingAs($a['t2']);
        $slug = $a['slug'];

        // Orden de t1.
        $this->get("/{$slug}/work-orders/{$a['wo1']->id}")->assertForbidden()->assertSee('ya no está asignada');
        $this->post("/{$slug}/work-orders/{$a['wo1']->id}/finish")->assertForbidden();
        $this->get("/{$slug}/delivery-notes/create/work-order/{$a['wo1']->id}")->assertForbidden();
        $this->post("/{$slug}/delivery-notes/store", $this->remito(['work_order_id' => $a['wo1']->id, 'assignment_type' => 'work_order']))->assertForbidden();
        $this->get("/{$slug}/work-orders?status=in_progress")->assertOk()->assertDontSee("#{$a['wo1']->id}<");

        // Reporte, foto y remito de t1.
        $this->get("/{$slug}/reports/{$a['report1']->id}")->assertForbidden();
        $this->get("/files/reports/{$a['report1']->id}/photo")->assertNotFound();
        $this->get("/{$slug}/delivery-notes/{$a['note1']->number}")->assertNotFound();

        // Mantenimiento ya hecho por t1: t2 no lo puede desmarcar.
        $this->post("/{$slug}/building-check/{$a['b1']->id}/done", ['assignment_type' => 'maintenance'])->assertForbidden();
        $this->assertNotNull($a['visit1']->fresh());

        $this->assertSame('in_progress', $a['wo1']->fresh()->status);
    }

    public function test_the_owner_technician_does_see_their_own_work(): void
    {
        $a = $this->a;
        $this->actingAs($a['t1']);

        $this->get("/{$a['slug']}/work-orders/{$a['wo1']->id}")->assertOk();
        $this->get("/{$a['slug']}/reports/{$a['report1']->id}")->assertOk();
        $this->get("/files/reports/{$a['report1']->id}/photo")->assertOk();
        $this->get("/{$a['slug']}/delivery-notes/{$a['note1']->number}")->assertOk();
    }

    public function test_a_technician_cannot_sign_the_monthly_maintenance_of_a_building_not_assigned_to_them(): void
    {
        $a = $this->a;
        $this->actingAs($a['t2']); // b1 es de t1
        $before = DeliveryNote::where('user_id', $a['t2']->id)->count();

        $this->get("/{$a['slug']}/delivery-notes/create/building/{$a['b1']->id}?assignment_type=maintenance")->assertForbidden();
        $this->post("/{$a['slug']}/delivery-notes/store", $this->remito(['building_id' => $a['b1']->id, 'month' => now()->subMonth()->month, 'year' => now()->subMonth()->year]))->assertForbidden();

        // Ni una inspección donde no es el inspector (b1 no tiene inspector).
        $this->post("/{$a['slug']}/delivery-notes/store", $this->remito(['building_id' => $a['b1']->id, 'assignment_type' => 'inspection']))->assertForbidden();

        $this->assertSame($before, DeliveryNote::where('user_id', $a['t2']->id)->count());

        // Su propio edificio sí.
        $this->post("/{$a['slug']}/delivery-notes/store", $this->remito(['building_id' => $a['b2']->id, 'elevator_quantity' => 3, 'freight_elevator_quantity' => 1]))->assertSessionHasNoErrors();
        $this->assertSame($before + 1, DeliveryNote::where('user_id', $a['t2']->id)->count());
    }

    public function test_the_work_order_remito_always_uses_the_order_building(): void
    {
        $a = $this->a;
        $this->actingAs($a['t1']);

        $this->post("/{$a['slug']}/delivery-notes/store", $this->remito([
            'work_order_id' => $a['wo1']->id,
            'assignment_type' => 'work_order',
            'building_id' => $a['b2']->id, // manipulado: otro edificio de la empresa
        ]))->assertSessionHasNoErrors();

        $note = DeliveryNote::where('work_order_id', $a['wo1']->id)->sole();
        $this->assertSame($a['b1']->id, $note->building_id);
        $this->assertSame('completed', $a['wo1']->fresh()->status);
    }

    public function test_technician_lists_and_counters_only_show_their_own_work(): void
    {
        $a = $this->a;

        // t1 ve su remito; t2 no.
        $this->actingAs($a['t1'])->get("/{$a['slug']}/delivery-notes")->assertOk()->assertSee($a['note1']->number);
        $this->actingAs($a['t2'])->get("/{$a['slug']}/delivery-notes")->assertOk()->assertDontSee($a['note1']->number);

        // Reportes: cada uno los suyos.
        $this->actingAs($a['t2'])->get("/{$a['slug']}/reports")->assertOk()->assertDontSee($a['report1']->description);

        // Inspección hecha en b2 (de t2) por OTRO técnico no debe "descontar"
        // máquinas de t1, que no tiene inspecciones asignadas; y una inspección
        // en un edificio que no es de t2 no debe descontarle a t2.
        BuildingVisit::factory()->create(['building_id' => $a['b1']->id, 'user_id' => $a['t1']->id, 'assignment_type' => 'inspection']);

        $response = $this->actingAs($a['t2'])->get("/{$a['slug']}/buildings")->assertOk();
        $this->assertSame(4, $response->viewData('inspectionTotalMachines'));
        $this->assertSame(4, $response->viewData('inspectionRemaining'));
    }

    public function test_technician_planillas_only_show_their_visits(): void
    {
        $a = $this->a;

        $this->actingAs($a['t2'])->get("/{$a['slug']}/my-templates")->assertOk()->assertDontSee($a['b1']->name);
        $this->actingAs($a['t1'])->get("/{$a['slug']}/my-templates")->assertOk()->assertSee($a['b1']->name);

        // Planilla de un técnico: solo admins de la misma empresa.
        $this->actingAs($a['t1'])->get("/{$a['slug']}/users/{$a['t2']->id}/template")->assertNotFound();
        $this->actingAs($a['admin'])->get("/{$a['slug']}/users/{$a['t1']->id}/template")->assertOk();
        $this->actingAs($a['admin'])->get("/{$a['slug']}/users/{$this->b['t1']->id}/template")->assertNotFound();
    }

    /*
    |--------------------------------------------------------------------------
    | ROL TÉCNICO: SIN ACCIONES ADMINISTRATIVAS
    |--------------------------------------------------------------------------
    */

    public function test_technician_has_no_administrative_access(): void
    {
        $a = $this->a;
        $this->actingAs($a['t1']);

        $this->get("/{$a['slug']}/clients")->assertNotFound();
        $this->get("/{$a['slug']}/clients/{$a['client']->id}")->assertNotFound();
        $this->get("/{$a['slug']}/delivery-notes/{$a['note1']->number}/pdf")->assertNotFound();
        $this->get("/{$a['slug']}/whatsapp/connect")->assertNotFound();

        foreach (['/admin', BuildingResource::getUrl(), StockItemResource::getUrl(), ReceivableResource::getUrl(), QuoteResource::getUrl(), UserResource::getUrl()] as $url) {
            $this->get($url)->assertRedirect();
        }

        // El perfil no permite escalar privilegios ni mudarse de empresa.
        $this->patch("/{$a['slug']}/profile", [
            'name' => 'Técnico', 'email' => $a['t1']->email,
            'role' => 'admin', 'company_id' => $this->b['company']->id, 'is_super_admin' => true,
        ]);
        $fresh = $a['t1']->fresh();
        $this->assertSame('technician', $fresh->role);
        $this->assertSame($a['company']->id, $fresh->company_id);
        $this->assertFalse((bool) $fresh->is_super_admin);
    }

    /*
    |--------------------------------------------------------------------------
    | FLUJO COMPLETO
    |--------------------------------------------------------------------------
    */

    public function test_quote_to_work_order_to_materials_to_stock_to_receivable(): void
    {
        $a = $this->a;
        Subscription::create(['company_id' => $a['company']->id, 'provider' => 'mercadopago', 'plan' => 'profesional', 'status' => 'authorized', 'amount' => 119000, 'current_period_end' => now()->addMonth()]);

        // 1. Presupuesto aprobado.
        $quote = $a['quote']->fresh();
        $quote->update(['status' => 'approved', 'amount' => 250000]);

        // 2. Orden de trabajo para t1 con 3 contactores (stock 10).
        $order = WorkOrder::factory()->create(['company_id' => $a['company']->id, 'building_id' => $a['b1']->id, 'status' => 'pending']);
        $order->users()->attach($a['t1']->id);
        $this->actingAs($a['admin']);
        WorkOrderMaterial::create(['work_order_id' => $order->id, 'stock_item_id' => $a['item']->id, 'quantity' => 3]);
        $this->assertEquals(10, $a['item']->fresh()->current_stock); // todavía no se descuenta

        // 3. El técnico la toma y la cierra firmando el remito.
        $this->actingAs($a['t1'])->post("/{$a['slug']}/work-orders/{$order->id}/start")->assertRedirect();
        $this->assertSame('in_progress', $order->fresh()->status);
        $this->actingAs($a['t1'])->post("/{$a['slug']}/delivery-notes/store", $this->remito([
            'work_order_id' => $order->id, 'assignment_type' => 'work_order', 'description' => 'Cambio de contactores',
        ]))->assertSessionHasNoErrors();

        // 4. Orden completa, remito creado, stock descontado UNA vez.
        $this->assertSame('completed', $order->fresh()->status);
        $this->assertNotNull($order->fresh()->deliveryNote);
        $this->assertEquals(7, $a['item']->fresh()->current_stock);
        $this->assertSame(1, StockMovement::withoutGlobalScopes()->where('work_order_id', $order->id)->count());

        // Reintentar el remito (doble envío) no vuelve a descontar.
        $this->actingAs($a['t1'])->post("/{$a['slug']}/delivery-notes/store", $this->remito(['work_order_id' => $order->id, 'assignment_type' => 'work_order']))->assertStatus(409);
        $this->assertEquals(7, $a['item']->fresh()->current_stock);

        // 5. Cobro desde el presupuesto aprobado; pago parcial y total.
        $this->actingAs($a['admin']);
        Livewire::test(ListQuotes::class)->callTableAction('generateReceivable', $quote, data: ['concept' => 'Presupuesto', 'amount' => 250000, 'due_date' => now()->addDays(10)->toDateString()]);
        $receivable = Receivable::where('quote_id', $quote->id)->sole();
        app(ReceivableService::class)->registerPayment($receivable, 100000, today(), 'transfer', null, $a['admin']);
        $this->assertSame(150000.0, $receivable->fresh()->balance());
        app(ReceivableService::class)->registerPayment($receivable->fresh(), 150000, today(), 'cash', null, $a['admin']);
        $this->assertSame(Receivable::PAID, $receivable->fresh()->status);

        // Nada de esto tocó a la empresa B.
        $this->assertEquals(10, StockItem::withoutGlobalScopes()->find($this->b['item']->id)->current_stock);
        $this->assertSame(0, Receivable::withoutGlobalScopes()->where('company_id', $this->b['company']->id)->whereNotNull('quote_id')->count());
    }

    /*
    |--------------------------------------------------------------------------
    | TÉCNICOS: NÚMERO DE TELÉFONO
    |--------------------------------------------------------------------------
    */

    public function test_phone_numbers_are_normalized_validated_and_shown(): void
    {
        $cases = [
            '11 2345-6789' => '5491123456789',
            '011 15 2345-6789' => '5491123456789',
            '+54 9 11 2345-6789' => '5491123456789',
            '(0351) 15 123-4567' => '5493511234567',
            '+54 351 123 4567' => '5493511234567',
            '5491123456789' => '5491123456789', // ya guardado: no cambia
        ];

        foreach ($cases as $input => $stored) {
            $this->assertSame($stored, PhoneNumber::normalize($input), $input);
        }

        foreach (['123', 'abc', '11 2345 678', '+1 415 555 0101', '0000000000', '15 2345-6789', '11 2345-6789 int 45'] as $invalid) {
            $this->assertNull(PhoneNumber::normalize($invalid), $invalid);
        }

        $this->assertSame('+54 9 11 2345-6789', PhoneNumber::format('5491123456789'));
        $this->assertSame('+54 9 351 123-4567', PhoneNumber::format('5493511234567'));
        $this->assertSame('12345', PhoneNumber::format('12345')); // dato viejo: se muestra tal cual
    }

    public function test_technician_form_uses_a_plain_phone_number(): void
    {
        $this->actingAs($this->a['admin']);

        Livewire::test(CreateUser::class)
            ->assertSee('Número de teléfono')
            ->assertDontSee('WhatsApp')
            ->fillForm(['name' => 'Técnica Nueva', 'email' => 'nueva@alfa.test', 'phone' => '0351 15 123-4567', 'password' => 'clave-segura-1'])
            ->call('create')
            ->assertHasNoFormErrors();

        $user = User::where('email', 'nueva@alfa.test')->sole();
        $this->assertSame('5493511234567', $user->phone);
        $this->assertSame($this->a['company']->id, $user->company_id);
        $this->assertSame('technician', $user->role);

        Livewire::test(CreateUser::class)
            ->fillForm(['name' => 'Mal', 'email' => 'mal@alfa.test', 'phone' => '123', 'password' => 'clave-segura-1'])
            ->call('create')
            ->assertHasFormErrors(['phone']);

        // Al editar se muestra con formato y se guarda igual.
        Livewire::test(EditUser::class, ['record' => $user->getRouteKey()])
            ->assertFormSet(['phone' => '+54 9 351 123-4567'])
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertSame('5493511234567', $user->fresh()->phone);
    }
}
