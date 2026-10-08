<?php

namespace Tests\Feature\Tenancy;

use App\Models\Building;
use App\Models\BuildingVisit;
use App\Models\DeliveryNote;
use App\Models\Quote;
use App\Models\Report;
use App\Models\WorkOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Empresa A intentando llegar a datos de Empresa B por las rutas web
 * (app de técnicos). Cada test arma A y B y ataca con IDs / slugs / tokens
 * de B estando autenticado como usuario de A.
 */
class WebCrossTenantAccessTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    private array $a;

    private array $b;

    protected function setUp(): void
    {
        parent::setUp();

        $this->a = $this->makeTenant();
        $this->b = $this->makeTenant();
    }

    private function urlA(string $path): string
    {
        return '/'.$this->a['company']->slug.$path;
    }

    public function test_user_cannot_use_another_company_slug(): void
    {
        // GET: vuelve a su propia pantalla, sin ver nada de la otra empresa.
        $this->actingAs($this->a['technician'])
            ->get('/'.$this->b['company']->slug.'/dashboard')
            ->assertRedirect(route('dashboard', ['company' => $this->a['company']->slug]))
            ->assertDontSee($this->b['company']->name);

        $this->actingAs($this->a['admin'])
            ->get('/'.$this->b['company']->slug.'/clients')
            ->assertRedirect(url('/admin'));

        // Escrituras: rechazo directo.
        $status = $this->actingAs($this->a['technician'])
            ->post('/'.$this->b['company']->slug.'/work-orders/1/start')
            ->status();

        $this->assertContains($status, [403, 404]);
    }

    public function test_guest_is_redirected_to_login_on_company_routes(): void
    {
        $this->get($this->urlA('/dashboard'))->assertRedirect('/login');
        $this->get($this->urlA('/delivery-notes'))->assertRedirect('/login');
        $this->post($this->urlA('/delivery-notes/store'))->assertRedirect('/login');
    }

    public function test_delivery_note_of_other_company_is_not_visible(): void
    {
        $noteB = DeliveryNote::factory()->create([
            'building_id' => $this->b['building']->id,
            'user_id' => $this->b['technician']->id,
        ]);
        $noteA = DeliveryNote::factory()->create([
            'building_id' => $this->a['building']->id,
            'user_id' => $this->a['technician']->id,
        ]);

        // Mismo número (00000001) en ambas empresas: debe resolverse SIEMPRE
        // dentro de la empresa de la URL, nunca devolver el de B.
        $this->assertSame($noteA->number, $noteB->number);

        $this->actingAs($this->a['technician'])
            ->get($this->urlA('/delivery-notes/'.$noteB->number))
            ->assertOk()
            ->assertSee($noteA->description)
            ->assertDontSee($noteB->description);

        $this->actingAs($this->a['technician'])
            ->get($this->urlA('/delivery-notes'))
            ->assertOk()
            ->assertSee($this->a['building']->name)
            ->assertDontSee($this->b['building']->name);
    }

    public function test_delivery_note_pdf_of_other_company_is_not_reachable(): void
    {
        $noteB = DeliveryNote::factory()->create([
            'building_id' => $this->b['building']->id,
            'user_id' => $this->b['technician']->id,
            'number' => '00000077',
        ]);

        $this->actingAs($this->a['admin'])
            ->get($this->urlA('/delivery-notes/'.$noteB->number.'/pdf'))
            ->assertNotFound();
    }

    public function test_public_delivery_note_token_is_scoped_to_company(): void
    {
        $noteB = DeliveryNote::factory()->create([
            'building_id' => $this->b['building']->id,
            'user_id' => $this->b['technician']->id,
        ]);

        $this->get($this->urlA('/public/delivery-notes/'.$noteB->public_token))
            ->assertNotFound();

        $this->get('/'.$this->b['company']->slug.'/public/delivery-notes/'.$noteB->public_token)
            ->assertOk();
    }

    public function test_public_quote_token_is_scoped_to_company(): void
    {
        $quoteB = Quote::factory()->create(['building_id' => $this->b['building']->id]);

        $this->get($this->urlA('/quote/'.$quoteB->public_token))->assertNotFound();
        $this->get('/'.$this->b['company']->slug.'/quote/'.$quoteB->public_token)->assertOk();
    }

    public function test_cannot_open_delivery_note_form_for_other_company_building_or_work_order(): void
    {
        $workOrderB = WorkOrder::factory()->inProgress()->create(['building_id' => $this->b['building']->id]);

        $this->actingAs($this->a['technician'])
            ->get($this->urlA('/delivery-notes/create/building/'.$this->b['building']->id))
            ->assertNotFound();

        $this->actingAs($this->a['technician'])
            ->get($this->urlA('/delivery-notes/create/work-order/'.$workOrderB->id))
            ->assertNotFound();
    }

    public function test_cannot_store_delivery_note_for_other_company_building(): void
    {
        $this->actingAs($this->a['technician'])
            ->post($this->urlA('/delivery-notes/store'), $this->deliveryPayload([
                'building_id' => $this->b['building']->id,
            ]))
            ->assertNotFound();

        $this->assertSame(0, DeliveryNote::withoutGlobalScopes()->count());
        $this->assertSame(0, BuildingVisit::withoutGlobalScopes()->count());
    }

    public function test_cannot_store_delivery_note_for_other_company_work_order(): void
    {
        $workOrderB = WorkOrder::factory()->inProgress()->create(['building_id' => $this->b['building']->id]);
        $workOrderB->users()->attach($this->b['technician']->id);

        $this->actingAs($this->a['technician'])
            ->post($this->urlA('/delivery-notes/store'), $this->deliveryPayload([
                'building_id' => $this->a['building']->id,
                'work_order_id' => $workOrderB->id,
                'assignment_type' => 'work_order',
            ]))
            ->assertNotFound();

        $this->assertSame('in_progress', $workOrderB->fresh()->status);
        $this->assertSame(0, DeliveryNote::withoutGlobalScopes()->count());
    }

    public function test_cannot_add_participants_from_other_company(): void
    {
        $this->actingAs($this->a['technician'])
            ->from($this->urlA('/delivery-notes/create/building/'.$this->a['building']->id))
            ->post($this->urlA('/delivery-notes/store'), $this->deliveryPayload([
                'participants' => [$this->a['technician']->id, $this->b['technician']->id],
            ]))
            ->assertSessionHasErrors('participants.1');

        $this->assertSame(0, DeliveryNote::withoutGlobalScopes()->count());
    }

    public function test_cannot_start_or_finish_other_company_work_order(): void
    {
        $pendingB = WorkOrder::factory()->create(['building_id' => $this->b['building']->id]);
        $inProgressB = WorkOrder::factory()->inProgress()->create(['building_id' => $this->b['building']->id]);

        $this->actingAs($this->a['technician'])
            ->post($this->urlA('/work-orders/'.$pendingB->id.'/start'))
            ->assertNotFound();

        $this->actingAs($this->a['admin'])
            ->post($this->urlA('/work-orders/'.$inProgressB->id.'/finish'))
            ->assertNotFound();

        $this->assertSame('pending', $pendingB->fresh()->status);
        $this->assertSame('in_progress', $inProgressB->fresh()->status);
    }

    public function test_work_order_list_only_shows_own_company(): void
    {
        $woA = WorkOrder::factory()->create(['building_id' => $this->a['building']->id, 'notes' => 'ORDEN-DE-A']);
        $woA->users()->attach($this->a['technician']->id);
        $woB = WorkOrder::factory()->create(['building_id' => $this->b['building']->id, 'notes' => 'ORDEN-DE-B']);
        $woB->users()->attach($this->b['technician']->id);

        $this->actingAs($this->a['technician'])
            ->get($this->urlA('/work-orders'))
            ->assertOk()
            ->assertSee($this->a['building']->name)
            ->assertDontSee($this->b['building']->name);
    }

    public function test_reports_of_other_company_are_not_visible(): void
    {
        $reportB = Report::factory()->create([
            'building_id' => $this->b['building']->id,
            'user_id' => $this->b['technician']->id,
            'description' => 'REPORTE-PRIVADO-DE-B',
        ]);

        $this->actingAs($this->a['technician'])
            ->get($this->urlA('/reports/'.$reportB->id))
            ->assertNotFound();

        $this->actingAs($this->a['technician'])
            ->get($this->urlA('/reports'))
            ->assertOk()
            ->assertDontSee('REPORTE-PRIVADO-DE-B');
    }

    public function test_cannot_create_report_for_other_company_building(): void
    {
        $this->actingAs($this->a['technician'])
            ->post($this->urlA('/reports'), [
                'building_id' => $this->b['building']->id,
                'elevator_number' => 'Ascensor 1',
                'description' => 'Intento cruzado',
                'priority' => 'alta',
                'photos' => [UploadedFile::fake()->image('foto.jpg')],
            ])
            ->assertSessionHasErrors('building_id');

        $this->assertSame(0, Report::withoutGlobalScopes()->count());
    }

    public function test_report_form_does_not_list_other_company_buildings(): void
    {
        // Nombres fijos: en esta página van serializados con @json.
        $this->a['building']->update(['name' => 'EdificioDeA']);
        $this->b['building']->update(['name' => 'EdificioDeB']);

        $this->actingAs($this->a['technician'])
            ->get($this->urlA('/reports/create'))
            ->assertOk()
            ->assertSee($this->a['building']->name)
            ->assertDontSee($this->b['building']->name);
    }

    public function test_cannot_uncheck_visit_on_other_company_building(): void
    {
        $visitB = BuildingVisit::factory()->create([
            'building_id' => $this->b['building']->id,
            'user_id' => $this->b['technician']->id,
        ]);

        $this->actingAs($this->a['technician'])
            ->post($this->urlA('/building-check/'.$this->b['building']->id.'/done'), [
                'assignment_type' => 'maintenance',
            ])
            ->assertNotFound();

        $this->assertNotNull($visitB->fresh());
    }

    public function test_admin_cannot_view_template_of_other_company_user(): void
    {
        $this->actingAs($this->a['admin'])
            ->get($this->urlA('/users/'.$this->b['technician']->id.'/template'))
            ->assertNotFound();

        $this->actingAs($this->a['admin'])
            ->get($this->urlA('/users/'.$this->b['technician']->id.'/template/day/'.now()->format('Y-m-d')))
            ->assertNotFound();
    }

    public function test_building_lists_only_show_own_company(): void
    {
        $this->actingAs($this->a['technician'])
            ->get($this->urlA('/buildings'))
            ->assertOk()
            ->assertSee($this->a['building']->name)
            ->assertDontSee($this->b['building']->name);

        $this->actingAs($this->a['technician'])
            ->get($this->urlA('/buildings/all'))
            ->assertOk()
            ->assertSee($this->a['building']->name)
            ->assertDontSee($this->b['building']->name);
    }

    public function test_admin_client_list_and_detail_only_show_own_company(): void
    {
        $clientA = $this->a['building']->client;
        $clientB = $this->b['building']->client;

        $this->actingAs($this->a['admin'])
            ->get($this->urlA('/clients'))
            ->assertOk()
            ->assertSee($clientA->name)
            ->assertDontSee($clientB->name);

        $this->actingAs($this->a['admin'])
            ->get($this->urlA('/clients/'.$clientA->id))
            ->assertOk()
            ->assertSee($this->a['building']->name);

        $this->actingAs($this->a['admin'])
            ->get($this->urlA('/clients/'.$clientB->id))
            ->assertNotFound();
    }

    public function test_relations_do_not_leak_other_company_records(): void
    {
        $buildingB = $this->b['building'];

        $this->actingAs($this->a['admin']);

        // Con sesión de A, ni siquiera navegando relaciones desde un
        // modelo de B se obtienen datos de B.
        $this->assertNull($buildingB->client()->first());
        $this->assertSame(0, Building::whereKey($buildingB->id)->count());
    }

    private function deliveryPayload(array $overrides = []): array
    {
        return array_merge([
            'building_id' => $this->a['building']->id,
            'assignment_type' => 'maintenance',
            'description' => 'Mantenimiento mensual',
            'month' => now()->month,
            'year' => now()->year,
            'elevator_quantity' => 1,
            'freight_elevator_quantity' => 0,
            'signature_name' => 'Técnico',
            'signature' => $this->validSignature(),
        ], $overrides);
    }
}
