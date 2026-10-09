<?php

namespace Tests\Feature\Flows;

use App\Filament\Pages\CompanyExports;
use App\Filament\Resources\Reports\Pages\ListReports;
use App\Models\Building;
use App\Models\Company;
use App\Models\CompanyExport;
use App\Models\DeliveryNote;
use App\Models\ElevatorDocument;
use App\Models\Quote;
use App\Models\Receivable;
use App\Models\ReceivablePayment;
use App\Models\Report;
use App\Models\StockItem;
use App\Models\User;
use App\Services\Insights\MaintenanceAgenda;
use Carbon\Carbon;
use Database\Seeders\DemoValidationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use OpenSpout\Reader\XLSX\Reader;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;
use ZipArchive;

/**
 * Validación con datos realistas (DemoValidationSeeder): dos empresas, sus
 * técnicos, clientes, edificios, contratos con distintas frecuencias,
 * remitos, inspecciones, órdenes, reportes, presupuestos, stock, documentos
 * y usuarios del portal. Sobre esos datos se ejecutan los flujos reales y se
 * verifica el resultado funcional (no solo un 200).
 */
class ValidationScenarioTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Storage::fake('local');
        Storage::fake('public');
        $this->travelTo(Carbon::parse('2026-10-14 09:00'));
        $this->seed(DemoValidationSeeder::class);
    }

    private function user(string $email): User
    {
        return User::withoutGlobalScopes()->where('email', $email)->firstOrFail();
    }

    private function building(string $name): Building
    {
        return Building::withoutGlobalScopes()->where('name', $name)->firstOrFail();
    }

    private function agenda(string $type = 'maintenance', int $month = 10): array
    {
        $this->actingInPanel($this->user('admin@demo-norte.test'));

        return app(MaintenanceAgenda::class)->rows($month, 2026, $type)
            ->mapWithKeys(fn ($r) => [$r['building']->name => $r['status']])->all();
    }

    public function test_the_seeder_is_reproducible_non_destructive_and_never_runs_in_production(): void
    {
        $counts = fn () => [Company::count(), User::withoutGlobalScopes()->count(), DeliveryNote::withoutGlobalScopes()->count()];
        $before = $counts();
        $this->assertSame([2, 10], array_slice($before, 0, 2)); // Norte 3 + 3 portal, Sur 3 + 1 portal
        $sur = Company::where('slug', 'demo-sur')->sole();
        $this->assertSame(4, User::withoutGlobalScopes()->where('company_id', $sur->id)->count()); // cada cuenta en su empresa

        $this->seed(DemoValidationSeeder::class); // segunda vez: no toca nada
        $this->assertSame($before, $counts());

        $this->app->detectEnvironment(fn () => 'production');
        Company::query()->each(fn ($c) => $c->update(['slug' => $c->slug.'-x'])); // ni siquiera con la base "libre"
        (new DemoValidationSeeder)->run();
        $this->assertSame($before, $counts());
    }

    public function test_agenda_reflects_the_flexible_monthly_work(): void
    {
        $this->assertEqualsCanonicalizing([
            'Torre Libertador' => 'done',
            'Edificio Juncal' => 'not_done',      // remito firmado "no realizado"
            'Plaza Belgrano' => 'pending',        // a mitad de mes no está vencido
            'Galería Cabildo' => 'pending',
        ], collect($this->agenda())->only(['Torre Libertador', 'Edificio Juncal', 'Plaza Belgrano', 'Galería Cabildo'])->all());
        $this->assertSame(['done'], array_values(array_unique($this->agenda('maintenance', 9))));
        $this->assertSame('done', $this->agenda('inspection', 9)['Torre Libertador']);
        $this->assertSame('pending', $this->agenda('inspection')['Edificio Juncal']);

        // La técnica completa Plaza Belgrano desde su lista; Lucas no puede firmarlo.
        $carla = $this->user('carla@demo-norte.test');
        $belgrano = $this->building('Plaza Belgrano');
        $payload = ['building_id' => $belgrano->id, 'description' => 'Mantenimiento completo.', 'month' => 10, 'year' => 2026, 'elevator_quantity' => 2, 'freight_elevator_quantity' => 0,
            'assignment_type' => 'maintenance', 'performed' => '1', 'signature_name' => 'Firma', 'signature' => $this->validSignature()];

        $this->actingAs($this->user('lucas@demo-norte.test'))->get('/demo-norte/buildings')->assertOk()
            ->assertSee('Torre Libertador')->assertDontSee('Plaza Belgrano');
        $this->post('/demo-norte/delivery-notes/store', $payload)->assertForbidden();
        $this->actingAs($carla)->post('/demo-norte/delivery-notes/store', $payload)->assertSessionHasNoErrors();
        $this->assertSame('done', $this->agenda()['Plaza Belgrano']);

        // Después de fin de mes, lo que quedó sin hacer sí está vencido.
        $this->travelTo(Carbon::parse('2026-11-02 09:00'));
        $this->assertSame('overdue', $this->agenda('maintenance', 10)['Galería Cabildo']);
    }

    public function test_stock_and_billing_follow_the_real_flows(): void
    {
        $this->actingInPanel($this->user('admin@demo-norte.test'));

        $contactor = StockItem::where('code', 'CT-220')->sole();
        $this->assertEquals(5, $contactor->fresh()->current_stock);             // 6 recibidos − 1 usado en la orden
        $this->assertTrue(StockItem::where('code', 'PB-01')->sole()->isLow());

        // Una cobranza por contrato (sin cobro retroactivo), por el monto de su período.
        $receivables = Receivable::query()->where('source', 'service')->with('service')->get();
        $this->assertCount(4, $receivables);
        $this->assertEqualsCanonicalizing(['monthly', 'quarterly', 'semiannual', 'annual'], $receivables->pluck('service.frequency')->all());
        $receivables->each(fn ($r) => $this->assertEquals($r->service->amount, $r->amount));
        $this->assertSame(1, ReceivablePayment::count());
    }

    public function test_each_portal_user_sees_only_what_was_shared_for_their_buildings(): void
    {
        $torre = $this->building('Torre Libertador');
        $juncal = $this->building('Edificio Juncal');
        $belgrano = $this->building('Plaza Belgrano');
        $sharedReport = Report::withoutGlobalScopes()->where('building_id', $torre->id)->sole();
        $internalReport = Report::withoutGlobalScopes()->where('building_id', $juncal->id)->sole();
        $sharedQuote = Quote::withoutGlobalScopes()->where('building_id', $torre->id)->sole();
        $draftQuote = Quote::withoutGlobalScopes()->where('building_id', $juncal->id)->sole();
        $certificate = ElevatorDocument::withoutGlobalScopes()->where('type', 'certificate')->sole();
        $plan = ElevatorDocument::withoutGlobalScopes()->where('type', 'plan')->sole();

        // La administración: sus dos edificios y solo lo compartido.
        $sofia = $this->user('admin@adm-rivadavia.test');
        $this->actingAs($sofia)->get(route('portal.home'))->assertOk()
            ->assertSee('Torre Libertador')->assertSee('Edificio Juncal')->assertDontSee('Plaza Belgrano')->assertDontSee('Demo Sur');
        $this->get(route('portal.building', $torre))->assertOk()
            ->assertSee('Iluminación de cabina')->assertSee('Modernización de iluminación')->assertSee('Habilitación municipal')
            ->assertDontSee('Plano de sala de máquinas')->assertDontSee('Lubricación de guías'); // remito de mantenimiento no compartido
        $this->get(route('portal.building', $juncal))->assertOk()->assertDontSee('Ruido en máquina')->assertDontSee('Cambio de rodamientos');
        $this->get(route('portal.report', $sharedReport))->assertOk();
        $photo = $sharedReport->photos()->withoutGlobalScopes()->first();
        $this->get(route('portal.report-photo', [$sharedReport, $photo]))->assertOk();
        $this->get(route('portal.document', $certificate))->assertOk();
        $this->get(route('portal.quote', $sharedQuote))->assertOk();
        foreach ([route('portal.report', $internalReport), route('portal.quote', $draftQuote), route('portal.document', $plan), route('portal.building', $belgrano)] as $url) {
            $this->get($url)->assertNotFound();
        }

        // Cuando el admin comparte el reporte interno, aparece.
        $this->actingInPanel($this->user('admin@demo-norte.test'));
        Livewire::test(ListReports::class)->call('updateTableColumnState', 'shared_with_client', (string) $internalReport->getKey(), true);
        $this->actingAs($sofia)->get(route('portal.report', $internalReport))->assertOk()->assertSee('Ruido en máquina');

        // El encargado: solo Juncal.
        $this->actingAs($this->user('encargado@adm-rivadavia.test'))->get(route('portal.building', $torre))->assertNotFound();
        $this->get(route('portal.home'))->assertOk()->assertSee('Edificio Juncal')->assertDontSee('Torre Libertador');

        // El consorcio: su remito de orden de trabajo compartido.
        $woNote = DeliveryNote::withoutGlobalScopes()->whereNotNull('work_order_id')->sole();
        $this->actingAs($this->user('consejo@plaza-belgrano.test'))->get(route('portal.building', $belgrano))->assertOk()->assertSee('Remito '.$woNote->number);
        $this->get(route('portal.delivery-note', $woNote))->assertOk()->assertSee('contactor')->assertSee('Contactor 220V');
        $this->get(route('portal.report', $sharedReport))->assertNotFound();

        // Un portal de otra empresa no ve nada de Demo Norte, ni entra al panel.
        $sur = $this->user('portal@consorcio-sur.test');
        $this->actingAs($sur)->get(route('portal.home'))->assertOk()->assertSee('Quilmes')->assertDontSee('Torre Libertador');
        $this->get(route('portal.building', $torre))->assertNotFound();
        $this->get(route('portal.document', $certificate))->assertNotFound();
        $this->get('/demo-norte/buildings')->assertRedirect(route('portal.home'));
        $this->get('/admin')->assertRedirect(route('portal.home'));
    }

    public function test_each_company_exports_its_own_complete_data(): void
    {
        $sheets = $this->exportFor('admin@demo-norte.test', $zipNames, $text);

        $rows = fn (string $sheet) => count($sheets[$sheet]) - 1;
        $this->assertSame(3, $rows('Clientes'));
        $this->assertSame(4, $rows('Edificios'));
        $this->assertSame(10, $rows('Ascensores'));     // 3+1, 2, 2, 1+1
        $this->assertSame(4, $rows('Contratos'));
        $this->assertSame(6, $rows('Usuarios'));        // admin, 2 técnicos, 3 portal
        $this->assertSame(6, $rows('Asignaciones'));
        $this->assertSame(6, $rows('Mantenimientos'));  // 4 del mes pasado + 2 de este mes
        $this->assertSame(1, $rows('Inspecciones'));
        $this->assertSame(2, $rows('Órdenes de trabajo'));
        $this->assertSame(1, $rows('Materiales usados'));
        $this->assertSame(2, $rows('Reportes'));
        $this->assertSame(8, $rows('Remitos'));
        $this->assertSame(2, $rows('Presupuestos'));
        $this->assertSame(3, $rows('Ítems de presupuestos'));
        $this->assertSame(3, $rows('Stock'));
        $this->assertSame(1, $rows('Pagos recibidos'));
        $this->assertSame(2, $rows('Documentos de ascensores'));

        // Relaciones legibles: el remito "no realizado" y quién lo compartió.
        $notDone = collect($sheets['Mantenimientos'])->first(fn ($r) => ($r[4] ?? null) === 'Edificio Juncal' && $r[1] === '10/2026');
        $this->assertSame(['No', 'Lucas Fernández'], [$notDone[7], $notDone[5]]);
        $this->assertSame('Portal del cliente', collect($sheets['Usuarios'])->firstWhere(2, 'encargado@adm-rivadavia.test')[3]);

        // Adjuntos: 2 fotos + 2 documentos, ninguno de Demo Sur.
        $this->assertSame(2, $zipNames->filter(fn ($n) => str_starts_with($n, 'adjuntos/reportes/'))->count());
        $this->assertSame(2, $zipNames->filter(fn ($n) => str_starts_with($n, 'adjuntos/documentos/'))->count());
        $this->assertStringNotContainsString('Demo Sur', $text);
        $this->assertStringNotContainsString('Quilmes', $text);
        $this->assertStringNotContainsString('$2y$', $text);
        $this->assertStringNotContainsString('data:image', $text);

        // Demo Sur exporta lo suyo y nada de Norte.
        $sur = $this->exportFor('admin@demo-sur.test', $zipNames, $text);
        $this->assertSame(1, count($sur['Edificios']) - 1);
        $this->assertStringNotContainsString('Libertador', $text);
        $this->assertStringNotContainsString('rivadavia.test', $text);
    }

    /** Pide la exportación desde la pantalla, la procesa el scheduler y la descarga. */
    private function exportFor(string $email, &$zipNames, &$text): array
    {
        $admin = $this->user($email);
        $this->actingInPanel($admin);
        Livewire::test(CompanyExports::class)->call('requestExport')->assertHasNoErrors();
        auth()->logout();
        $this->artisan('exports:process')->assertSuccessful();

        $export = CompanyExport::withoutGlobalScopes()->where('company_id', $admin->company_id)->latest('id')->firstOrFail();
        $this->assertSame(CompanyExport::COMPLETED, $export->status);
        $this->assertSame([], $export->warnings ?? []);
        $response = $this->actingAs($admin)->get(route('company-exports.download', $export))->assertOk();

        $dir = sys_get_temp_dir().'/ascento-validation-'.uniqid();
        mkdir($dir);
        $zipPath = $dir.'/export.zip';
        file_put_contents($zipPath, $response->streamedContent());

        $zip = new ZipArchive;
        $zip->open($zipPath);
        $zipNames = collect(range(0, $zip->numFiles - 1))->map(fn ($i) => $zip->getNameIndex($i));
        $xlsx = $dir.'/datos.xlsx';
        file_put_contents($xlsx, $zip->getFromName($zipNames->first(fn ($n) => str_ends_with($n, '.xlsx'))));

        $inner = new ZipArchive;
        $inner->open($xlsx);
        $text = $zip->getFromName('LEEME.txt');
        for ($i = 0; $i < $inner->numFiles; $i++) {
            $text .= $inner->getFromIndex($i);
        }
        $inner->close();
        $zip->close();

        $reader = new Reader;
        $reader->open($xlsx);
        $sheets = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $sheets[$sheet->getName()][] = $row->toArray();
            }
        }
        $reader->close();
        exec('rm -rf '.escapeshellarg($dir));

        return $sheets;
    }
}
