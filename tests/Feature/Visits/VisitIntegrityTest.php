<?php

namespace Tests\Feature\Visits;

use App\Models\Building;
use App\Models\BuildingVisit;
use App\Models\User;
use App\Services\Visits\VisitSchedule;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Integridad del historial con la identidad de visitas (Etapa 5, revisión):
 * las migraciones se pueden repetir y revertir sin dejar la base a medias,
 * los duplicados históricos siguen consultables y el chequeo previo a
 * producción es de solo lectura y dice qué va a pasar con cada grupo.
 */
class VisitIntegrityTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    private array $a;

    private Building $building;

    private User $tech;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->travelTo(Carbon::parse('2026-10-14 09:00'));
        $this->a = $this->makeTenant();
        $this->building = $this->a['building'];
        $this->tech = $this->a['technician'];
    }

    private function migration(string $name)
    {
        return require database_path("migrations/{$name}.php");
    }

    /** Visita como las anteriores a la etapa (sin identidad: solo por la base). */
    private function legacyVisit(int $month, array $extra = []): int
    {
        return DB::table('building_visits')->insertGetId([
            'company_id' => $this->a['company']->id, 'building_id' => $this->building->id, 'user_id' => $this->tech->id,
            'source' => 'building', 'visit_type' => 'fixed', 'assignment_type' => 'maintenance', 'status' => 'done',
            'month' => $month, 'year' => 2026, 'visited_at' => now(), 'created_at' => now(), 'updated_at' => now(), ...$extra,
        ]);
    }

    private function legacyNote(int $visitId, int $number, int $month): void
    {
        DB::table('delivery_notes')->insert([
            'company_id' => $this->a['company']->id, 'building_id' => $this->building->id, 'building_visit_id' => $visitId,
            'user_id' => $this->tech->id, 'number' => $number, 'assignment_type' => 'maintenance', 'description' => 'x', 'month' => $month, 'year' => 2026,
            'elevator_quantity' => 1, 'freight_elevator_quantity' => 0, 'performed' => true, 'signature_name' => 'T', 'signature' => 'x',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_the_migrations_can_run_again_after_an_interruption_and_roll_back_repeatedly(): void
    {
        $slots = $this->migration('2026_11_02_100000_create_visit_plans_and_visit_slots');
        $photos = $this->migration('2026_11_02_100100_create_photo_sections_and_visit_photos');
        $tokens = $this->migration('2026_11_02_100200_add_idempotency_keys_to_visits_and_photos');
        $fromVisits = $this->migration('2026_11_10_100000_photos_from_visits_and_company_settings');
        $visit = $this->legacyVisit(9);

        // Correr de nuevo sobre una base ya migrada (o cortada a mitad): no falla ni duplica.
        $slots->up();
        $tokens->up();
        $fromVisits->up();
        $this->assertSame('maintenance:2026-09:1', DB::table('building_visits')->where('id', $visit)->value('period_key'));

        // Dos ciclos completos de reversión y vuelta (antes, el segundo fallaba en MySQL).
        foreach ([1, 2] as $cycle) {
            $fromVisits->down();
            $tokens->down();
            $photos->down();
            $slots->down();
            $this->assertFalse(Schema::hasColumn('building_visits', 'period_key'), "ciclo {$cycle}");
            $this->assertFalse(Schema::hasTable('building_visit_plans'));

            $slots->up();
            $photos->up();
            $tokens->up();
            $fromVisits->up();
            $this->assertTrue(Schema::hasColumn('building_visits', 'submission_token'));
            $this->assertTrue(Schema::hasColumn('building_visits', 'in_progress'));
            $this->assertTrue(Schema::hasColumn('companies', 'photos_work_order'));
            $this->assertSame('maintenance:2026-09:1', DB::table('building_visits')->where('id', $visit)->value('period_key'));
        }

        $this->assertSame(1, DB::table('building_visits')->count());
    }

    public function test_reverting_never_deletes_visits_or_remitos(): void
    {
        $this->legacyNote($this->legacyVisit(9), 1, 9);
        $this->legacyNote($this->legacyVisit(9), 2, 9);

        $this->migration('2026_11_10_100000_photos_from_visits_and_company_settings')->down();
        $this->migration('2026_11_02_100200_add_idempotency_keys_to_visits_and_photos')->down();
        $this->migration('2026_11_02_100100_create_photo_sections_and_visit_photos')->down();
        $this->migration('2026_11_02_100000_create_visit_plans_and_visit_slots')->down();

        $this->assertSame(2, DB::table('building_visits')->count());
        $this->assertSame(2, DB::table('delivery_notes')->count());
    }

    public function test_duplicates_that_do_not_count_remain_in_the_history(): void
    {
        // Dos visitas del mismo mes de antes del índice, cada una con su remito.
        $kept = BuildingVisit::factory()->create(['building_id' => $this->building->id, 'user_id' => $this->tech->id, 'month' => 9, 'year' => 2026]);
        $this->legacyNote($kept->id, 1, 9);
        $extra = $this->legacyVisit(9, ['slot' => 1, 'period_key' => null]);
        $this->legacyNote($extra, 2, 9);

        $this->assertCount(1, VisitSchedule::load([$this->building->id], 9, 2026)->evaluate($this->building->id, 'maintenance', 9, 2026)['visits']);

        // Siguen en el historial: listado de remitos del técnico y del admin.
        $this->actingAs($this->tech)->get("/{$this->a['company']->slug}/delivery-notes")->assertOk()
            ->assertSee('Remito 1 ·')->assertSee('Remito 2 ·');
        $this->actingAs($this->tech)->get("/{$this->a['company']->slug}/delivery-notes/2")->assertOk();
        $this->assertSame(2, BuildingVisit::withoutGlobalScopes()->where('building_id', $this->building->id)->where('month', 9)->count());
    }

    public function test_the_duplicate_check_is_read_only_and_explains_each_group(): void
    {
        $kept = BuildingVisit::factory()->create(['building_id' => $this->building->id, 'user_id' => $this->tech->id, 'month' => 9, 'year' => 2026]);
        $this->legacyNote($kept->id, 1, 9);
        $this->legacyNote($this->legacyVisit(9, ['period_key' => null]), 2, 9); // dos remitos el mismo mes → revisar
        $this->legacyVisit(9, ['period_key' => null]);                          // y una visita sin remito

        $before = DB::table('building_visits')->orderBy('id')->get()->toJson();
        $writes = [];
        DB::listen(function ($query) use (&$writes) {
            if (! preg_match('/^\s*(select|pragma)\b/i', $query->sql)) {
                $writes[] = $query->sql;
            }
        });

        $this->assertSame(0, Artisan::call('visits:check-duplicates'));
        $output = Artisan::output();

        foreach (['Grupos duplicados: 1', 'Visitas que quedan como historial sin contar: 2', 'Grupos con más de un remito (revisar): 1', 'Revisar', $kept->id.' (1 remito)', 'Nada se borra'] as $expected) {
            $this->assertStringContainsString($expected, $output);
        }

        $this->assertSame([], $writes);
        $this->assertSame($before, DB::table('building_visits')->orderBy('id')->get()->toJson());
    }

    public function test_the_duplicate_check_before_migrating_predicts_which_visit_keeps_its_identity(): void
    {
        $this->migration('2026_11_10_100000_photos_from_visits_and_company_settings')->down();
        $this->migration('2026_11_02_100200_add_idempotency_keys_to_visits_and_photos')->down();
        $this->migration('2026_11_02_100100_create_photo_sections_and_visit_photos')->down();
        $this->migration('2026_11_02_100000_create_visit_plans_and_visit_slots')->down();

        $older = $this->legacyVisit(9);
        $withNote = $this->legacyVisit(9);
        $this->legacyNote($withNote, 1, 9);

        Artisan::call('visits:check-duplicates');
        $output = Artisan::output();
        $this->assertStringContainsString('Conservará identidad', $output);
        $this->assertMatchesRegularExpression('/\|\s*'.$withNote.' \(1 remito\)\s*\|\s*'.$older.' \(0\)\s*\|/', $output);

        $this->migration('2026_11_02_100000_create_visit_plans_and_visit_slots')->up();
        $this->migration('2026_11_02_100100_create_photo_sections_and_visit_photos')->up();
        $this->migration('2026_11_02_100200_add_idempotency_keys_to_visits_and_photos')->up();
        $this->migration('2026_11_10_100000_photos_from_visits_and_company_settings')->up();
        $this->assertNotNull(DB::table('building_visits')->where('id', $withNote)->value('period_key'));
        $this->assertNull(DB::table('building_visits')->where('id', $older)->value('period_key'));
    }

    public function test_without_duplicates_the_check_says_so(): void
    {
        BuildingVisit::factory()->create(['building_id' => $this->building->id, 'user_id' => $this->tech->id, 'month' => 9, 'year' => 2026]);

        $this->artisan('visits:check-duplicates')->expectsOutputToContain('No hay visitas periódicas duplicadas')->assertSuccessful();
    }
}
