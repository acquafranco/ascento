<?php

namespace Tests\Feature\Clients;

use App\Filament\Resources\Clients\Pages\ImportClients;
use App\Models\Client;
use App\Models\User;
use App\Services\Clients\ClientImport;
use App\Services\Staff\StaffManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use OpenSpout\Common\Entity\Cell\FormulaCell;
use OpenSpout\Common\Entity\Cell\NumericCell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Importación de clientes desde Excel/CSV: validación, duplicados (en el
 * archivo y en la empresa), tipo de cliente, permisos, aislamiento entre
 * empresas, todo-o-nada y reenvíos.
 */
class ClientImportTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    private array $a;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->a = $this->makeTenant();
        $this->giveCompanyPlan($this->a['company'], 'empresa');
    }

    private function csv(string $content, string $name = 'clientes.csv'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $content);
    }

    /** @param list<list<mixed>> $rows */
    private function xlsx(array $rows): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'imp').'.xlsx';
        $writer = new Writer;
        $writer->openToFile($path);
        foreach ($rows as $row) {
            $writer->addRow(new Row(array_map(fn ($v) => $v instanceof \OpenSpout\Common\Entity\Cell ? $v : new StringCell((string) $v, null), $row)));
        }
        $writer->close();

        return UploadedFile::fake()->createWithContent('clientes.xlsx', (string) file_get_contents($path));
    }

    private function clientCount(): int
    {
        return Client::withoutGlobalScopes()->where('company_id', $this->a['company']->id)->count();
    }

    private function page(?User $user = null)
    {
        $this->actingInPanel(($user ?? $this->a['admin'])->fresh());

        return Livewire::test(ImportClients::class);
    }

    public function test_imports_several_clients_with_empty_optional_fields_and_the_chosen_type(): void
    {
        $before = $this->clientCount();
        $page = $this->page()
            ->set('file', $this->csv("Nombre;Persona de Contacto;Teléfono;E-mail\nConsorcio Sol;Ana;011 4567-8900;sol@test.com\nEdificio Luna;;;\n Torre Norte ;Luis;0351 15 123;\n"))
            ->call('analyzeFile')->assertSet('step', 'preview')->assertSee('3 nuevos')
            ->set('type', 'empresa')
            ->call('confirm')->assertSet('step', 'done')->assertSee('3 importados');

        $this->assertSame($before + 3, $this->clientCount());
        $luna = Client::withoutGlobalScopes()->where('name', 'Edificio Luna')->sole();
        $this->assertNull($luna->phone);
        $this->assertNull($luna->email);
        $this->assertSame('empresa', $luna->type);
        $this->assertSame((int) $this->a['company']->id, (int) $luna->company_id);
        // Teléfono como texto, con el 0 inicial.
        $this->assertSame('011 4567-8900', Client::withoutGlobalScopes()->where('name', 'Consorcio Sol')->sole()->phone);
        $this->assertSame(0, \App\Models\Building::withoutGlobalScopes()->where('company_id', $this->a['company']->id)->where('client_id', $luna->id)->count());
    }

    public function test_xlsx_rows_without_name_bad_emails_and_formulas_are_rejected_and_numbers_keep_their_digits(): void
    {
        $before = $this->clientCount();
        $this->page()
            ->set('file', $this->xlsx([
                ['nombre', 'persona_contacto', 'telefono', 'email'],
                ['Cliente Bueno', '', new NumericCell(1145678900, null), 'bueno@test.com'],
                ['', 'Sin nombre', '', ''],
                ['Cliente Mal Correo', '', '', 'no-es-correo'],
                [new FormulaCell('=HYPERLINK("http://x","y")', null), '', '', ''],
            ]))
            ->call('analyzeFile')->assertSet('step', 'preview')
            ->assertSee('Falta el nombre.')->assertSee('El correo no es válido.')->assertSee('tiene una fórmula')
            ->call('confirm')->assertSee('1 importados')->assertSee('3 rechazados');

        $this->assertSame($before + 1, $this->clientCount());
        $this->assertSame('1145678900', Client::withoutGlobalScopes()->where('name', 'Cliente Bueno')->sole()->phone);
    }

    public function test_the_template_has_the_four_columns_and_is_readable(): void
    {
        $response = $this->page()->call('downloadTemplate');
        $response->assertFileDownloaded('plantilla-clientes.xlsx');
        $content = base64_decode($response->effects['download']['content']);
        $path = tempnam(sys_get_temp_dir(), 'tpl');
        file_put_contents($path, $content);
        $read = app(ClientImport::class)->read($path, 'plantilla.xlsx');
        $this->assertSame(['nombre', 'persona_contacto', 'telefono', 'email'], $read['headers']);
        $this->assertSame(['name' => 0, 'contact_person' => 1, 'phone' => 2, 'email' => 3], app(ClientImport::class)->guessMapping($read['headers']));
    }

    public function test_empty_invalid_or_oversized_files_are_refused(): void
    {
        $this->page()->set('file', $this->csv(''))->call('analyzeFile')->assertHasErrors('file');
        $this->page()->set('file', $this->csv("nombre;telefono\n"))->call('analyzeFile')->assertHasErrors('file');
        $this->page()->set('file', $this->csv("PK\x03\x04basura", 'clientes.csv'))->call('analyzeFile')->assertHasErrors('file');
        $this->page()->set('file', $this->csv('no es un excel', 'clientes.xlsx'))->call('analyzeFile')->assertHasErrors('file');
        $this->page()->set('file', UploadedFile::fake()->create('clientes.pdf', 10, 'application/pdf'))->call('analyzeFile')->assertHasErrors('file');
        $this->page()->set('file', UploadedFile::fake()->create('clientes.csv', 3000, 'text/csv'))->call('analyzeFile')->assertHasErrors('file');
        $this->page()->set('file', $this->csv("nombre\n".implode("\n", array_map(fn ($i) => "Cliente {$i}", range(1, ClientImport::MAX_ROWS + 1)))))->call('analyzeFile')->assertHasErrors('file');
    }

    public function test_unrecognized_headers_ask_for_the_column_mapping(): void
    {
        $page = $this->page()->set('file', $this->csv("Razón X,Contacto Y,Cel Z\nConsorcio Uno,Ana,1155\n"))->call('analyzeFile')
            ->assertSet('step', 'map')
            ->set('mapping.name', '0')->set('mapping.contact_person', '1')->set('mapping.phone', '2')
            ->call('applyMapping')->assertSet('step', 'preview')->call('confirm');
        $this->assertSame('Ana', Client::withoutGlobalScopes()->where('name', 'Consorcio Uno')->sole()->contact_person);
        // Una columna para dos campos, no.
        $this->page()->set('file', $this->csv("A,B\nX,Y\n"))->call('analyzeFile')->set('mapping.name', '0')->set('mapping.phone', '0')->call('applyMapping')->assertHasErrors('mapping');
    }

    public function test_duplicates_in_the_file_and_with_existing_clients_are_left_for_review(): void
    {
        $this->actingAs($this->a['admin']);
        Client::create(['name' => 'Consorcio Avenida', 'email' => 'avenida@test.com', 'type' => 'consorcio']);
        Client::create(['name' => 'Edificio Plaza', 'phone' => '011 1234-5678', 'type' => 'consorcio']);
        $before = $this->clientCount();

        $page = $this->page()->set('file', $this->csv(
            "nombre,telefono,email\n".
            "Otro Nombre,,AVENIDA@test.com\n".     // mismo correo que uno existente: duplicado claro
            "edificio  plaza,1112345678,\n".        // mismo nombre normalizado y teléfono: claro
            "Consorcio Avenida,,\n".                // mismo nombre solo: posible
            "Cliente Nuevo,1199998888,\n".
            "Cliente Nuevo,1199998888,\n"           // repite la fila anterior del archivo
        ))->call('analyzeFile');

        $rows = collect(\Illuminate\Support\Facades\Cache::get('client-import:'.$this->a['company']->id.':'.$this->a['admin']->id.':'.$page->get('token'))['rows'])->keyBy('line');
        $this->assertSame('clear', $rows[2]['matchLevel']);
        $this->assertSame('clear', $rows[3]['matchLevel']);
        $this->assertSame('possible', $rows[4]['matchLevel']);
        $this->assertNull($rows[5]['matchLevel']);
        $this->assertSame('clear', $rows[6]['matchLevel']);
        // Solo lo nuevo viene tildado.
        $this->assertSame([5], $page->get('selected'));

        // La persona decide: el de mismo nombre era otro cliente.
        $page->set('selected', [5, 4])->call('confirm')->assertSee('2 importados')->assertSee('3 omitidos');
        $this->assertSame($before + 2, $this->clientCount());
        // Los existentes no se tocaron.
        $this->assertNull(Client::withoutGlobalScopes()->where('name', 'Edificio Plaza')->sole()->email);
    }

    public function test_same_names_in_another_company_do_not_count_and_nothing_crosses_companies(): void
    {
        $b = $this->makeTenant();
        $this->giveCompanyPlan($b['company'], 'empresa');
        $this->actingAs($b['admin']);
        Client::create(['name' => 'Consorcio Compartido', 'email' => 'mismo@test.com', 'type' => 'consorcio']);
        $bCount = Client::withoutGlobalScopes()->where('company_id', $b['company']->id)->count();

        $page = $this->page()->set('file', $this->csv("nombre,email,company_id\nConsorcio Compartido,mismo@test.com,{$b['company']->id}\n"))
            ->call('analyzeFile')->assertSee('1 nuevos');
        $this->assertSame([2], $page->get('selected'));
        $page->call('confirm');

        $this->assertSame(1, Client::withoutGlobalScopes()->where('company_id', $this->a['company']->id)->where('name', 'Consorcio Compartido')->count());
        $this->assertSame($bCount, Client::withoutGlobalScopes()->where('company_id', $b['company']->id)->count());
    }

    public function test_only_users_who_manage_clients_can_import(): void
    {
        $this->actingAs($this->a['admin']);
        $viewer = app(StaffManager::class)->create($this->a['admin'], ['name' => 'Ve', 'email' => 've@test.com', 'password' => 'clave-segura-123'], ['clients' => 'view']);
        $manager = app(StaffManager::class)->create($this->a['admin'], ['name' => 'Gestiona', 'email' => 'ge@test.com', 'password' => 'clave-segura-123'], ['clients' => 'manage']);

        $this->actingInPanel($viewer->fresh());
        $this->assertContains($this->get(ImportClients::getUrl())->getStatusCode(), [403, 404]);
        $this->get('/admin/clients')->assertOk()->assertDontSee('Importar desde Excel');

        $this->flushSession();
        $this->actingInPanel($manager->fresh());
        $this->get(ImportClients::getUrl())->assertOk();
        $this->get('/admin/clients')->assertOk()->assertSee('Importar desde Excel');

        $this->flushSession();
        $this->actingAs($this->a['technician']->fresh());
        $this->assertNotSame(200, $this->get(ImportClients::getUrl())->getStatusCode());
    }

    public function test_invalid_type_is_refused_and_the_plan_limit_makes_it_all_or_nothing(): void
    {
        $before = $this->clientCount();
        $this->page()->set('file', $this->csv("nombre\nUno\nDos\n"))->call('analyzeFile')->set('type', 'inventado')->call('confirm')->assertHasErrors('type');
        $this->assertSame($before, $this->clientCount());
        $this->assertSame(['hospital', 'consorcio', 'empresa', 'particular'], array_keys(Client::TYPES));

        // Inicial: 50 clientes. Con 49 cargados, importar 2 no deja ninguno a medias.
        $this->giveCompanyPlan($this->a['company'], 'inicial');
        $this->actingAs($this->a['admin']);
        while ($this->clientCount() < 49) {
            Client::create(['name' => 'Relleno '.$this->clientCount(), 'type' => 'consorcio']);
        }
        $this->page()->set('file', $this->csv("nombre\nUno más\nDos más\n"))->call('analyzeFile')->call('confirm')->assertHasErrors('rows');
        $this->assertSame(49, $this->clientCount());
    }

    public function test_resubmitting_the_same_import_does_not_create_duplicates(): void
    {
        $page = $this->page()->set('file', $this->csv("nombre\nCliente Único\n"))->call('analyzeFile');
        $token = $page->get('token');
        $page->call('confirm')->assertSee('1 importados');
        $this->assertSame(1, Client::withoutGlobalScopes()->where('name', 'Cliente Único')->count());

        // Mismo componente, segundo envío (doble clic o reenvío): no crea nada.
        $page->call('confirm');
        $this->assertSame(1, Client::withoutGlobalScopes()->where('name', 'Cliente Único')->count());

        // Volver a subir el mismo archivo: queda marcado como duplicado y sin tildar.
        $again = $this->page()->set('file', $this->csv("nombre\nCliente Único\n"))->call('analyzeFile');
        $this->assertSame([], $again->get('selected'));
        $this->assertNotSame($token, $again->get('token'));
    }
}
