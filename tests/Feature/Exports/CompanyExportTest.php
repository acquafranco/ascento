<?php

namespace Tests\Feature\Exports;

use App\Filament\Pages\CompanyExports;
use App\Models\Client;
use App\Models\CompanyExport;
use App\Models\CompanyExportDownload;
use App\Models\DeliveryNote;
use App\Models\Elevator;
use App\Models\ElevatorDocument;
use App\Models\MaintenanceService;
use App\Models\Quote;
use App\Models\Report;
use App\Models\ReportPhoto;
use App\Models\StockItem;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderMaterial;
use App\Services\Elevators\ElevatorDocumentService;
use App\Services\Exports\CompanyDataExporter;
use App\Services\Exports\CompanyExportService;
use App\Services\Reports\ReportPhotoService;
use App\Services\Stock\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use OpenSpout\Reader\XLSX\Reader;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;
use ZipArchive;

/**
 * Exportación de datos de negocio: contenido, aislamiento, secretos,
 * fórmulas, adjuntos, permisos, historial, vencimiento y fallas.
 */
class CompanyExportTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    private array $a;

    private array $b;

    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Storage::fake('local');
        Storage::fake('public');
        $this->a = $this->makeTenant();
        $this->b = $this->makeTenant();
        $this->tmp = sys_get_temp_dir().'/ascento-export-test-'.uniqid();
        mkdir($this->tmp);

        $this->seedCompanyA();
        $this->seedCompanyB();
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->tmp));
        parent::tearDown();
    }

    private function seedCompanyA(): void
    {
        $this->actingAs($this->a['admin']);
        $a = $this->a;

        // Textos "peligrosos" para Excel (los carga cualquier usuario).
        Client::whereKey($a['building']->client_id)->update(['name' => '=HYPERLINK("http://malo","clic")', 'notes' => '@SUM(1+1)']);
        $a['building']->update(['notes' => '+cmd|\' /C calc\'!A0']);

        MaintenanceService::create(['client_id' => $a['building']->client_id, 'building_id' => $a['building']->id, 'description' => '-2+3 Abono mensual', 'amount' => 120000, 'frequency' => 'quarterly', 'start_date' => '2026-01-01', 'payment_due_day' => 10, 'status' => 'active']);
        $item = StockItem::create(['name' => 'Contactor', 'unit' => 'unidad', 'cost' => 45000, 'min_stock' => 1, 'is_active' => true]);
        app(StockService::class)->receive($item, 10, $a['admin'], 'Stock inicial');
        $order = WorkOrder::factory()->create(['building_id' => $a['building']->id, 'type' => 'claim', 'status' => 'in_progress', 'unit' => 'Ascensor 1', 'component' => 'doors']);
        WorkOrderMaterial::create(['work_order_id' => $order->id, 'stock_item_id' => $item->id, 'quantity' => 2]);
        $order->update(['status' => 'completed', 'finished_at' => now()]);

        $report = Report::factory()->create(['building_id' => $a['building']->id, 'user_id' => $a['technician']->id, 'description' => 'Ruido en puertas']);
        app(ReportPhotoService::class)->add($report, [UploadedFile::fake()->image('f.jpg', 400, 300)]);
        app(ElevatorDocumentService::class)->store(Elevator::where('building_id', $a['building']->id)->first(),
            UploadedFile::fake()->createWithContent('cert.pdf', "%PDF-1.4\n%%EOF"), 'certificate', 'Habilitación 2026', null, $a['admin']);
        $quote = Quote::factory()->create(['building_id' => $a['building']->id, 'title' => 'Cambio de operador']);
        $quote->items()->create(['concept' => 'Operador', 'quantity' => 1, 'unit_price' => 250000]);

        auth()->logout();
    }

    private function seedCompanyB(): void
    {
        $this->actingAs($this->b['admin']);
        Client::whereKey($this->b['building']->client_id)->update(['name' => 'SECRETO-B Consorcio']);
        Report::factory()->create(['building_id' => $this->b['building']->id, 'user_id' => $this->b['technician']->id, 'description' => 'SECRETO-B reporte']);
        auth()->logout();
    }

    /** Pide (como admin A) y genera (como el scheduler) una exportación. */
    private function generate(): CompanyExport
    {
        $this->actingInPanel($this->a['admin']);
        Livewire::test(CompanyExports::class)->call('requestExport');
        auth()->logout();

        $this->artisan('exports:process')->assertSuccessful();

        return CompanyExport::withoutGlobalScopes()->latest('id')->firstOrFail();
    }

    /** @return array{zip: ZipArchive, xlsx: string, sheets: array<string, list<list<mixed>>>} */
    private function open(CompanyExport $export): array
    {
        $zip = new ZipArchive;
        $this->assertTrue($zip->open(Storage::disk('local')->path($export->path)) === true);

        $xlsxName = collect(range(0, $zip->numFiles - 1))->map(fn ($i) => $zip->getNameIndex($i))->first(fn ($n) => str_ends_with($n, '.xlsx'));
        $xlsx = $this->tmp.'/datos.xlsx';
        file_put_contents($xlsx, $zip->getFromName($xlsxName));

        $reader = new Reader;
        $reader->open($xlsx);
        $sheets = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $sheets[$sheet->getName()][] = $row->toArray();
            }
        }
        $reader->close();

        return ['zip' => $zip, 'xlsx' => $xlsx, 'sheets' => $sheets];
    }

    /** Todo el texto del ZIP (Excel descomprimido incluido), para buscar fugas. */
    private function allText(array $opened): string
    {
        $inner = new ZipArchive;
        $inner->open($opened['xlsx']);
        $text = '';
        for ($i = 0; $i < $inner->numFiles; $i++) {
            $text .= $inner->getFromIndex($i);
        }
        $text .= $opened['zip']->getFromName('LEEME.txt');

        return $text;
    }

    public function test_the_export_contains_the_company_data_in_readable_sheets_and_its_attachments(): void
    {
        $export = $this->generate();

        $this->assertSame(CompanyExport::COMPLETED, $export->status);
        $this->assertStringStartsWith('exports/'.$this->a['company']->id.'/', $export->path);
        $this->assertGreaterThan(0, $export->size);
        $this->assertSame($this->a['admin']->id, $export->requested_by);
        $this->assertTrue($export->expires_at->between(now()->addDays(6), now()->addDays(8)));

        $opened = $this->open($export);
        $sheets = $opened['sheets'];

        foreach (['Empresa', 'Clientes', 'Edificios', 'Ascensores', 'Documentos de ascensores', 'Contratos', 'Usuarios', 'Asignaciones', 'Órdenes de trabajo', 'Materiales usados', 'Reportes', 'Presupuestos', 'Ítems de presupuestos', 'Stock', 'Movimientos de stock', 'Archivos'] as $sheet) {
            $this->assertArrayHasKey($sheet, $sheets, "Falta la hoja {$sheet}");
        }
        $this->assertArrayNotHasKey('Pagos recibidos', $sheets); // sin datos → sin hoja vacía
        foreach ($sheets as $name => $rows) {
            $this->assertGreaterThan(1, count($rows), "La hoja {$name} quedó vacía");
        }

        // Encabezados legibles y relaciones por ID + nombre.
        $this->assertSame(['ID', 'Cliente ID', 'Cliente', 'Nombre'], array_slice($sheets['Edificios'][0], 0, 4));
        $building = collect($sheets['Edificios'])->firstWhere(0, $this->a['building']->id);
        $this->assertSame($this->a['building']->client_id, $building[1]);
        $order = $sheets['Órdenes de trabajo'][1];
        $this->assertSame(['Reclamo', 'Puertas y operador', 'Completado'], [$order[4], $order[5], $order[6]]);
        $this->assertEquals([2, 'Sí'], [$sheets['Materiales usados'][1][2], $sheets['Materiales usados'][1][5]]);
        $this->assertInstanceOf(\DateTimeInterface::class, $sheets['Reportes'][1][1]); // fechas como fechas

        // Adjuntos de la empresa, con índice.
        $names = collect(range(0, $opened['zip']->numFiles - 1))->map(fn ($i) => $opened['zip']->getNameIndex($i));
        $this->assertTrue($names->contains(fn ($n) => str_starts_with($n, 'adjuntos/reportes/') && str_ends_with($n, '-foto-1.jpg')));
        $this->assertTrue($names->contains(fn ($n) => str_starts_with($n, 'adjuntos/documentos/') && str_ends_with($n, '.pdf')));
        $this->assertTrue($names->contains('LEEME.txt'));
        $this->assertCount(2, array_filter($sheets['Archivos'], fn ($r) => ($r[2] ?? null) === 'Incluido'));
        $this->assertStringContainsString('No es un backup del servidor', $opened['zip']->getFromName('LEEME.txt'));
        $this->assertSame(array_sum($export->categories), $export->record_count);
    }

    public function test_no_other_company_data_and_no_secrets(): void
    {
        $this->a['admin']->forceFill(['remember_token' => 'TOKEN-RECORDAR-123', 'telegram_chat_id' => '999888777'])->save();
        $this->a['company']->forceFill(['whatsapp_access_token' => 'EAAG-SECRETO-WA'])->save();
        $note = DeliveryNote::factory()->create(['building_id' => $this->a['building']->id, 'signature' => $this->validSignature()]);

        $text = $this->allText($this->open($this->generate()));

        $this->assertStringNotContainsString('SECRETO-B', $text);
        $this->assertStringNotContainsString($this->b['admin']->email, $text);
        $this->assertStringNotContainsString('$2y$', $text);                  // hashes de contraseñas
        $this->assertStringNotContainsString('TOKEN-RECORDAR-123', $text);
        $this->assertStringNotContainsString('999888777', $text);
        $this->assertStringNotContainsString('EAAG-SECRETO-WA', $text);
        $this->assertStringNotContainsString($note->public_token, $text);     // links públicos
        $this->assertStringNotContainsString('data:image', $text);            // firmas dibujadas
        $this->assertStringNotContainsString(storage_path(), $text);          // rutas internas
    }

    public function test_user_text_never_becomes_an_excel_formula(): void
    {
        $opened = $this->open($this->generate());

        $client = collect($opened['sheets']['Clientes'])->firstWhere(0, $this->a['building']->client_id);
        $this->assertSame('=HYPERLINK("http://malo","clic")', $client[1]); // el dato original, intacto
        $this->assertSame('@SUM(1+1)', $client[6]);

        $inner = new ZipArchive;
        $inner->open($opened['xlsx']);
        for ($i = 0; $i < $inner->numFiles; $i++) {
            if (str_starts_with($inner->getNameIndex($i), 'xl/worksheets/')) {
                $this->assertStringNotContainsString('<f>', $inner->getFromIndex($i), 'Hay una fórmula en '.$inner->getNameIndex($i));
            }
        }

        // El dato guardado en la base no se modificó.
        $this->assertSame('=HYPERLINK("http://malo","clic")', Client::withoutGlobalScopes()->find($this->a['building']->client_id)->name);
    }

    public function test_download_requires_the_company_admin_and_is_logged(): void
    {
        $export = $this->generate();
        $url = route('company-exports.download', $export);

        $this->actingAs($this->a['admin'])->get($url)->assertOk()->assertDownload($export->file_name);
        $this->assertSame(1, CompanyExportDownload::withoutGlobalScopes()->where('company_export_id', $export->id)->where('user_id', $this->a['admin']->id)->count());

        $this->actingAs($this->a['technician'])->get($url)->assertNotFound();
        $this->actingAs($this->b['admin'])->get($url)->assertNotFound();
        $portal = User::factory()->create();
        $portal->forceFill(['role' => User::ROLE_CLIENT, 'company_id' => $this->a['company']->id, 'client_id' => $this->a['building']->client_id])->save();
        $this->actingAs($portal)->get($url)->assertNotFound();
        auth()->logout();
        $this->get($url)->assertRedirect('/login');
        $this->get('/storage/'.$export->path)->assertNotFound();

        $this->assertSame(1, CompanyExportDownload::withoutGlobalScopes()->count()); // solo la descarga válida

        // El historial lo muestra.
        $this->actingInPanel($this->a['admin'])->get(CompanyExports::getUrl())->assertOk()
            ->assertSee('Completada')->assertSee('Descargada 1 vez')->assertSee($this->a['admin']->name);
        $this->actingInPanel($this->b['admin'])->get(CompanyExports::getUrl())->assertOk()->assertDontSee($export->file_name);
    }

    public function test_expired_exports_cannot_be_downloaded_and_files_are_pruned_keeping_history(): void
    {
        $export = $this->generate();
        $path = $export->path;

        $this->travel(CompanyExport::KEEP_DAYS + 1)->days();
        $this->actingAs($this->a['admin'])->get(route('company-exports.download', $export))->assertNotFound();

        $this->artisan('exports:prune')->assertSuccessful();
        Storage::disk('local')->assertMissing($path);
        $fresh = $export->fresh();
        $this->assertNotNull($fresh->file_deleted_at);
        $this->assertNull($fresh->path);
        $this->assertSame(CompanyExport::COMPLETED, $fresh->status); // el historial queda
    }

    public function test_failures_are_recorded_without_secrets_and_can_be_retried(): void
    {
        $this->app->instance(CompanyDataExporter::class, new class extends CompanyDataExporter
        {
            public function export(CompanyExport $export): array
            {
                throw new \RuntimeException('SQLSTATE password=supersecreta host=10.0.0.5');
            }
        });

        $export = $this->generate();

        $this->assertSame(CompanyExport::FAILED, $export->status);
        $this->assertStringContainsString('No se pudo generar la exportación', $export->error);
        $this->assertStringNotContainsString('supersecreta', $export->error);
        $this->actingAs($this->a['admin'])->get(route('company-exports.download', $export))->assertNotFound();

        $this->app->forgetInstance(CompanyDataExporter::class);
        $this->assertSame(CompanyExport::COMPLETED, $this->generate()->status); // se puede reintentar
    }

    public function test_one_at_a_time_daily_limit_and_only_admins(): void
    {
        $service = app(CompanyExportService::class);
        $service->request($this->a['admin']);

        try {
            $service->request($this->a['admin']);
            $this->fail('Se aceptaron dos exportaciones a la vez.');
        } catch (ValidationException) {
        }

        CompanyExport::withoutGlobalScopes()->update(['status' => CompanyExport::COMPLETED]);
        for ($i = 0; $i < CompanyExport::DAILY_LIMIT - 1; $i++) {
            $service->request($this->a['admin']);
            CompanyExport::withoutGlobalScopes()->update(['status' => CompanyExport::COMPLETED]);
        }
        try {
            $service->request($this->a['admin']);
            $this->fail('Se superó el límite diario.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('máximo', collect($e->errors())->flatten()->first());
        }

        $this->actingAs($this->a['technician'])->get(CompanyExports::getUrl())->assertRedirect();
        $this->expectException(HttpException::class);
        $service->request($this->a['technician']);
    }

    public function test_missing_or_tampered_attachments_are_reported_not_followed(): void
    {
        $photo = ReportPhoto::withoutGlobalScopes()->where('company_id', $this->a['company']->id)->first();
        Storage::disk('local')->delete($photo->path);
        $doc = ElevatorDocument::withoutGlobalScopes()->where('company_id', $this->a['company']->id)->first();
        ElevatorDocument::withoutGlobalScopes()->whereKey($doc->id)->update(['path' => 'elevators/'.$this->a['company']->id.'/../../../.env']);

        $export = $this->generate();

        $this->assertSame(CompanyExport::COMPLETED, $export->status);
        $this->assertCount(2, $export->warnings);
        $opened = $this->open($export);
        $this->assertFalse(collect(range(0, $opened['zip']->numFiles - 1))->contains(fn ($i) => str_contains($opened['zip']->getNameIndex($i), '.env')));
        $this->assertCount(2, array_filter($opened['sheets']['Archivos'], fn ($r) => ($r[2] ?? null) === 'No incluido'));
    }
}
