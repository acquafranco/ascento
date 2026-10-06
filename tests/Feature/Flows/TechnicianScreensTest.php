<?php

namespace Tests\Feature\Flows;

use App\Models\Building;
use App\Models\BuildingVisit;
use App\Models\DeliveryNote;
use App\Models\Report;
use App\Models\WorkOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Cada pantalla de la app del técnico, con datos reales cargados, debe
 * renderizar sin errores (vistas, relaciones y rutas referenciadas).
 */
class TechnicianScreensTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    public function test_all_technician_screens_render_with_data(): void
    {
        $a = $this->makeTenant();
        $slug = $a['company']->slug;

        $workOrder = WorkOrder::factory()->inProgress()->create(['building_id' => $a['building']->id]);
        $workOrder->users()->attach($a['technician']->id);

        $visit = BuildingVisit::factory()->create([
            'building_id' => $a['building']->id,
            'user_id' => $a['technician']->id,
        ]);
        $visit->participants()->attach($a['technician']->id, ['role' => 'creator']);

        $note = DeliveryNote::factory()->create([
            'building_id' => $a['building']->id,
            'user_id' => $a['technician']->id,
            'building_visit_id' => $visit->id,
            'signature' => $this->validSignature(),
        ]);

        $report = Report::factory()->create([
            'building_id' => $a['building']->id,
            'user_id' => $a['technician']->id,
        ]);

        $pages = [
            '/dashboard',
            '/profile',
            '/buildings',
            '/buildings/all',
            '/my-templates',
            '/my-templates/day/'.now()->format('Y-m-d'),
            '/work-orders',
            '/work-orders?status=in_progress',
            '/work-orders?status=completed',
            '/delivery-notes',
            '/delivery-notes/'.$note->number,
            '/delivery-notes/create/building/'.$a['building']->id,
            '/delivery-notes/create/work-order/'.$workOrder->id,
            '/reports',
            '/reports/create',
            '/reports/'.$report->id,
        ];

        foreach ($pages as $page) {
            $this->actingAs($a['technician'])
                ->get("/{$slug}{$page}")
                ->assertOk();
        }

        $this->get("/{$slug}/public/delivery-notes/{$note->public_token}")->assertOk();
    }

    public function test_admin_web_screens_render(): void
    {
        $a = $this->makeTenant();
        $slug = $a['company']->slug;

        $this->actingAs($a['admin'])->get("/{$slug}/dashboard")->assertRedirect('/admin');
        $this->actingAs($a['admin'])->get("/{$slug}/clients")->assertOk();
        $this->actingAs($a['admin'])->get("/{$slug}/users/{$a['technician']->id}/template")->assertOk();
        $this->actingAs($a['admin'])
            ->get("/{$slug}/users/{$a['technician']->id}/template/day/".now()->format('Y-m-d'))
            ->assertOk();
    }

    public function test_invalid_dates_and_periods_do_not_crash(): void
    {
        $a = $this->makeTenant();
        $slug = $a['company']->slug;

        $this->actingAs($a['technician'])->get("/{$slug}/my-templates/day/2026-13-45")->assertNotFound();
        $this->actingAs($a['technician'])->get("/{$slug}/my-templates/day/not-a-date")->assertNotFound();
        $this->actingAs($a['technician'])->get("/{$slug}/my-templates?month=abc")->assertRedirect();
        $this->actingAs($a['technician'])->get("/{$slug}/my-templates?month=13&year=2026")->assertRedirect();
        $this->actingAs($a['admin'])->get("/{$slug}/users/{$a['technician']->id}/template?year=99999")->assertRedirect();
    }

    public function test_removed_dead_routes_no_longer_500(): void
    {
        $a = $this->makeTenant();
        $slug = $a['company']->slug;

        // Antes: 500 (métodos inexistentes / vista inexistente).
        $this->actingAs($a['admin'])->get("/{$slug}/clients/create")->assertNotFound();
        $this->actingAs($a['admin'])->get("/{$slug}/buildings/create")->assertNotFound();
        $this->actingAs($a['admin'])->delete("/{$slug}/clients/{$a['building']->client_id}")->assertStatus(405);
        $this->actingAs($a['technician'])->post("/{$slug}/building-check/{$a['building']->id}/failed")->assertNotFound();
    }

    public function test_building_check_unmarks_own_visit_only_on_assigned_buildings(): void
    {
        $a = $this->makeTenant();
        $slug = $a['company']->slug;

        $visit = BuildingVisit::factory()->create([
            'building_id' => $a['building']->id,
            'user_id' => $a['technician']->id,
            'assignment_type' => 'maintenance',
        ]);

        $this->actingAs($a['technician'])
            ->post("/{$slug}/building-check/{$a['building']->id}/done", ['assignment_type' => 'maintenance'])
            ->assertRedirect();

        $this->assertNull(BuildingVisit::withoutGlobalScopes()->find($visit->id));

        $unassigned = Building::factory()->create(['company_id' => $a['company']->id]);

        $this->actingAs($a['technician'])
            ->post("/{$slug}/building-check/{$unassigned->id}/done")
            ->assertForbidden();
    }
}
