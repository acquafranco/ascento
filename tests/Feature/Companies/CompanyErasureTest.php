<?php

namespace Tests\Feature\Companies;

use App\Filament\Resources\Companies\CompanyDeletion;
use App\Filament\Resources\Companies\CompanyResource;
use App\Filament\Resources\Companies\Pages\ListCompanies;
use App\Filament\Resources\Companies\Pages\ViewCompany;
use App\Models\Company;
use App\Models\PortalMembership;
use App\Models\User;
use App\Services\Companies\CompanyEraser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Eliminación definitiva de una empresa desde SuperAdmin: borra solo lo
 * suyo (filas en todas las tablas y archivos), conserva otras empresas, los
 * datos globales, el SuperAdmin y los usuarios del portal compartidos, y no
 * deja nada a medias. Solo el SuperAdmin, con confirmación explícita.
 */
class CompanyErasureTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    private array $a;

    private array $b;

    private User $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Storage::fake('local');
        Storage::fake('public');
        $this->a = $this->makeTenant();
        $this->b = $this->makeTenant();
        $this->root = User::factory()->superAdmin()->create(['company_id' => null]);

        $this->fill($this->a);
        $this->fill($this->b);
    }

    /** Una fila mínima válida (columnas obligatorias sin valor por defecto, con datos de relleno). */
    private function row(string $table, array $values): int
    {
        $columns = Schema::getColumns($table);
        $values = array_intersect_key($values, array_flip(array_column($columns, 'name')));

        foreach ($columns as $column) {
            $name = $column['name'];

            if ($name === 'id' || array_key_exists($name, $values) || $column['nullable'] || $column['default'] !== null || $column['auto_increment']) {
                continue;
            }

            $type = strtolower($column['type_name']);
            $values[$name] = match (true) {
                str_contains($type, 'int'), str_contains($type, 'dec'), str_contains($type, 'num'), str_contains($type, 'float'), str_contains($type, 'double') => 1,
                str_contains($type, 'date') || str_contains($type, 'time') => now(),
                str_contains($type, 'bool') || $type === 'tinyint' => 0,
                default => Str::lower(Str::random(10)),
            };
        }

        return (int) DB::table($table)->insertGetId($values);
    }

    /** Datos y archivos en todas las áreas de una empresa. */
    private function fill(array $t): void
    {
        $c = $t['company']->id;
        $u = $t['technician']->id;
        $bld = $t['building']->id;
        $now = ['created_at' => now(), 'updated_at' => now()];

        $client = $this->row('clients', ['company_id' => $c, 'name' => 'Cliente '.$c, ...$now]);
        DB::table('buildings')->where('id', $bld)->update(['client_id' => $client]);
        $elevator = $this->row('elevators', ['company_id' => $c, 'building_id' => $bld, 'label' => 'Equipo prueba', ...$now]);
        $this->row('elevator_documents', ['company_id' => $c, 'elevator_id' => $elevator, 'path' => "elevators/{$c}/doc.pdf", ...$now]);
        $service = $this->row('maintenance_services', ['company_id' => $c, 'client_id' => $client, 'building_id' => $bld, ...$now]);
        $visit = $this->row('building_visits', ['company_id' => $c, 'building_id' => $bld, 'user_id' => $u, 'visit_type' => 'fixed', 'assignment_type' => 'maintenance', 'month' => 10, 'year' => 2026, 'maintenance_service_id' => $service, ...$now]);
        DB::table('building_visit_participants')->insert(['building_visit_id' => $visit, 'user_id' => $u, 'role' => 'creator', ...$now]);
        $this->row('building_visit_plans', ['company_id' => $c, 'building_id' => $bld, ...$now]);
        $order = $this->row('work_orders', ['company_id' => $c, 'building_id' => $bld, 'type' => 'claim', 'priority' => 'medium', 'status' => 'pending', ...$now]);
        DB::table('work_order_user')->insert(['work_order_id' => $order, 'user_id' => $u, ...$now]);
        DB::table('work_order_participants')->insert(['work_order_id' => $order, 'user_id' => $u, ...$now]);
        $note = $this->row('delivery_notes', ['company_id' => $c, 'building_id' => $bld, 'user_id' => $u, 'building_visit_id' => $visit, 'work_order_id' => $order, 'number' => $c * 100, ...$now]);
        $section = $this->row('photo_sections', ['company_id' => $c, 'code' => 'pozo', 'name' => 'Pozo', ...$now]);
        $this->row('visit_photos', ['company_id' => $c, 'building_id' => $bld, 'building_visit_id' => $visit, 'delivery_note_id' => $note, 'photo_section_id' => $section, 'section_name' => 'Pozo', 'assignment_type' => 'maintenance', 'stage' => 'before', 'batch' => (string) Str::uuid(), 'path' => "visits/{$c}/a.jpg", 'thumb_path' => "visits/{$c}/a-thumb.jpg", 'uploaded_by' => $u, ...$now]);
        $report = $this->row('reports', ['company_id' => $c, 'user_id' => $u, 'building_id' => $bld, 'description' => 'Falla', 'photo' => "reports/legacy-{$c}.jpg", ...$now]);
        $this->row('report_photos', ['company_id' => $c, 'report_id' => $report, 'path' => "reports/{$c}/photos/r.jpg", ...$now]);
        $this->row('report_videos', ['company_id' => $c, 'report_id' => $report, 'path' => "reports/{$c}/videos/v.mp4", ...$now]);
        $quote = $this->row('quotes', ['company_id' => $c, 'building_id' => $bld, 'client_id' => $client, 'created_by' => $t['admin']->id, ...$now]);
        $this->row('quote_items', ['company_id' => $c, 'quote_id' => $quote, ...$now]);
        $this->row('quote_events', ['company_id' => $c, 'quote_id' => $quote, ...$now]);
        $receivable = $this->row('receivables', ['company_id' => $c, 'client_id' => $client, 'building_id' => $bld, 'quote_id' => $quote, 'maintenance_service_id' => $service, ...$now]);
        $this->row('receivable_payments', ['company_id' => $c, 'receivable_id' => $receivable, ...$now]);
        $item = $this->row('stock_items', ['company_id' => $c, ...$now]);
        $movement = $this->row('stock_movements', ['company_id' => $c, 'stock_item_id' => $item, 'work_order_id' => $order, ...$now]);
        $material = $this->row('work_order_materials', ['company_id' => $c, 'work_order_id' => $order, 'stock_item_id' => $item, 'stock_movement_id' => $movement, ...$now]);
        DB::table('stock_movements')->where('id', $movement)->update(['work_order_material_id' => $material]);
        $export = $this->row('company_exports', ['company_id' => $c, 'path' => "exports/{$c}/datos.zip", ...$now]);
        $this->row('company_export_downloads', ['company_id' => $c, 'company_export_id' => $export, 'user_id' => $t['admin']->id, ...$now]);
        $subscription = $this->row('subscriptions', ['company_id' => $c, 'provider' => 'manual', 'status' => 'active', ...$now]);
        $this->row('subscription_payments', ['company_id' => $c, 'subscription_id' => $subscription, ...$now]);
        $this->row('notifications', ['id' => (string) Str::uuid(), 'company_id' => $c, 'type' => 'x', 'notifiable_type' => User::class, 'notifiable_id' => $t['admin']->id, 'data' => '{}', ...$now]);
        $this->row('help_dismissals', ['user_id' => $t['admin']->id, 'key' => 'agenda_intro']);
        $this->row('push_subscriptions', ['subscribable_type' => User::class, 'subscribable_id' => $u, 'endpoint' => 'https://push.example/'.$c, ...$now]);
        DB::table('sessions')->insert(['id' => 'sess-'.$c, 'user_id' => $u, 'payload' => 'x', 'last_activity' => time()]);

        // Usuario del portal de esta empresa (con su acceso).
        $portal = User::factory()->create(['company_id' => $c, 'client_id' => $client, 'role' => User::ROLE_CLIENT]);
        DB::table('client_portal_buildings')->insert(['user_id' => $portal->id, 'building_id' => $bld, ...$now]);

        DB::table('companies')->where('id', $c)->update(['logo' => "companies/logos/logo-{$c}.png"]);

        foreach (["elevators/{$c}/doc.pdf", "visits/{$c}/a.jpg", "visits/{$c}/a-thumb.jpg", "reports/{$c}/photos/r.jpg", "reports/{$c}/videos/v.mp4", "exports/{$c}/datos.zip", "visits/{$c}/suelto.jpg"] as $path) {
            Storage::disk('local')->put($path, 'x');
        }
        Storage::disk('public')->put("reports/legacy-{$c}.jpg", 'x');
        Storage::disk('public')->put("companies/logos/logo-{$c}.png", 'x');
    }

    /** Filas por tabla de una empresa (todas las tablas con company_id). */
    private function snapshot(int $companyId): array
    {
        return collect(Schema::getTableListing(schemaQualified: false))
            ->filter(fn ($table) => Schema::hasColumn($table, 'company_id'))
            ->mapWithKeys(fn ($table) => [$table => DB::table($table)->where('company_id', $companyId)->count()])
            ->sortKeys()->all();
    }

    public function test_it_erases_only_that_company_with_its_rows_users_and_files(): void
    {
        $idA = $this->a['company']->id;
        $idB = $this->b['company']->id;
        $before = $this->snapshot($idB);
        $this->assertGreaterThan(20, collect($this->snapshot($idA))->filter()->count(), 'El escenario debe tener datos en casi todas las tablas.');
        $global = ['subscription_plans' => DB::table('subscription_plans')->count(), 'webhook_events' => DB::table('webhook_events')->count()];
        $usersA = DB::table('users')->where('company_id', $idA)->pluck('id')->all();

        $this->actingAs($this->root);
        $result = app(CompanyEraser::class)->erase(Company::find($idA), $this->root, $this->a['company']->name);
        $this->assertGreaterThan(30, $result['rows']);

        // Nada de A en ninguna tabla; B exactamente igual; datos globales intactos.
        $this->assertSame([], array_filter($this->snapshot($idA)));
        $this->assertSame($before, $this->snapshot($idB));
        $this->assertSame($global, ['subscription_plans' => DB::table('subscription_plans')->count(), 'webhook_events' => DB::table('webhook_events')->count()]);
        $this->assertNull(DB::table('companies')->find($idA));
        $this->assertNotNull(DB::table('companies')->find($idB));

        // Sin huérfanos: pivotes, sesiones, push y ayudas de los usuarios borrados.
        $this->assertSame(0, DB::table('users')->whereIn('id', $usersA)->count());
        foreach (['building_user', 'work_order_user', 'work_order_participants', 'building_visit_participants', 'client_portal_buildings', 'help_dismissals', 'sessions'] as $table) {
            $this->assertSame(0, DB::table($table)->whereIn('user_id', $usersA)->count(), $table);
        }
        $this->assertSame(0, DB::table('push_subscriptions')->whereIn('subscribable_id', $usersA)->count());
        $this->assertSame(1, DB::table('sessions')->where('user_id', $this->b['technician']->id)->count());
        $this->assertSame(1, DB::table('building_user')->where('building_id', $this->b['building']->id)->count());

        // El SuperAdmin sigue.
        $this->assertNotNull(User::find($this->root->id));

        // Archivos: los de A, todos; los de B, ninguno.
        foreach (['elevators', 'visits', 'reports', 'exports'] as $root) {
            $this->assertSame([], Storage::disk('local')->allFiles("{$root}/{$idA}"), $root);
            $this->assertNotSame([], Storage::disk('local')->allFiles("{$root}/{$idB}"), $root);
        }
        Storage::disk('public')->assertMissing("reports/legacy-{$idA}.jpg");
        Storage::disk('public')->assertMissing("companies/logos/logo-{$idA}.png");
        Storage::disk('public')->assertExists("reports/legacy-{$idB}.jpg");
        Storage::disk('public')->assertExists("companies/logos/logo-{$idB}.png");
    }

    public function test_a_portal_user_shared_with_another_company_is_kept_there(): void
    {
        $idA = $this->a['company']->id;
        $idB = $this->b['company']->id;
        $portal = User::where('company_id', $idA)->where('role', User::ROLE_CLIENT)->sole();
        $clientB = DB::table('clients')->where('company_id', $idB)->value('id');
        $membership = new PortalMembership(['user_id' => $portal->id, 'client_id' => $clientB]);
        $membership->forceFill(['company_id' => $idB])->save();

        $this->actingAs($this->root);
        app(CompanyEraser::class)->erase(Company::find($idA), $this->root, $this->a['company']->name);

        $portal->refresh();
        $this->assertSame([$idB, (int) $clientB], [(int) $portal->company_id, (int) $portal->client_id]);
        $this->assertSame([$idB], PortalMembership::where('user_id', $portal->id)->pluck('company_id')->map(fn ($id) => (int) $id)->all());
        $this->assertSame(0, DB::table('client_portal_buildings')->where('building_id', $this->a['building']->id)->count());
    }

    public function test_nothing_is_erased_without_the_exact_name_or_with_a_subscription_that_can_still_charge(): void
    {
        $company = Company::find($this->a['company']->id);
        $before = $this->snapshot($company->id);
        $this->actingAs($this->root);

        try {
            app(CompanyEraser::class)->erase($company, $this->root, 'otra cosa');
            $this->fail('Debía pedir el nombre exacto.');
        } catch (ValidationException) {
        }

        $this->row('subscriptions', ['company_id' => $company->id, 'provider' => 'mercadopago', 'provider_subscription_id' => 'pre-123', 'status' => 'authorized', 'created_at' => now(), 'updated_at' => now()]);
        $before = $this->snapshot($company->id);
        $this->assertNotEmpty(app(CompanyEraser::class)->preview($company)['blockers']);

        try {
            app(CompanyEraser::class)->erase($company, $this->root, $company->name);
            $this->fail('Una suscripción que cobra debía impedirlo.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('Mercado Pago', collect($e->errors())->flatten()->first());
        }

        $this->assertSame($before, $this->snapshot($company->id));
        Storage::disk('local')->assertExists("visits/{$company->id}/a.jpg");
    }

    public function test_only_the_super_admin_can_erase_and_must_confirm_in_the_panel(): void
    {
        $company = Company::find($this->a['company']->id);

        // Un admin de empresa: ni la pantalla ni el servicio.
        $this->actingInPanel($this->a['admin']);
        $this->assertNotSame(200, $this->get(CompanyResource::getUrl('index'))->status());
        try {
            CompanyResource::canAccess();
            $this->fail('Empresas es solo del SuperAdmin.');
        } catch (NotFoundHttpException) {
        }
        try {
            app(CompanyEraser::class)->erase($company, $this->a['admin'], $company->name);
            $this->fail('Solo el SuperAdmin.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
        $this->assertNotNull(Company::find($company->id));

        // SuperAdmin: la acción identifica la empresa y exige nombre y confirmación.
        $this->actingInPanel($this->root);
        $company->forceFill(['cuit' => '30712345679', 'business_name' => 'Ascensores Norte SRL'])->saveQuietly();
        $summary = (string) CompanyDeletion::summary($company->fresh());
        foreach ([$company->slug, '30-71234567-9', 'Ascensores Norte SRL', 'Edificios: 1', 'no es desactivar'] as $text) {
            $this->assertStringContainsString(e($text), $summary);
        }
        Livewire::test(ViewCompany::class, ['record' => $company->getRouteKey()])
            ->assertActionVisible('eraseCompany')
            ->mountAction('eraseCompany')
            ->setActionData(['confirmation' => 'Otra', 'understood' => true])
            ->callMountedAction()
            ->assertHasActionErrors(['confirmation']);
        $this->assertNotNull(Company::find($company->id));

        Livewire::test(ViewCompany::class, ['record' => $company->getRouteKey()])
            ->callAction('eraseCompany', ['confirmation' => $company->name, 'understood' => false])
            ->assertHasActionErrors(['understood']);
        $this->assertNotNull(Company::find($company->id));

        // También una empresa desactivada, desde la lista.
        $company->delete();
        Livewire::test(ListCompanies::class)
            ->filterTable('trashed', true)
            ->callTableAction('eraseCompany', $company, ['confirmation' => $company->name, 'understood' => true])
            ->assertHasNoTableActionErrors()
            ->assertNotified('Empresa eliminada definitivamente');
        $this->assertNull(Company::withTrashed()->find($company->id));
        $this->assertNotNull(Company::find($this->b['company']->id));
    }
}
