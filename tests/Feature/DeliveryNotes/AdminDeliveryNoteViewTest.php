<?php

namespace Tests\Feature\DeliveryNotes;

use App\Filament\Resources\DeliveryNotes\DeliveryNoteResource;
use App\Filament\Resources\DeliveryNotes\Pages\ListDeliveryNotes;
use App\Filament\Resources\DeliveryNotes\Pages\ViewDeliveryNote;
use App\Models\Client;
use App\Models\DeliveryNote;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\Staff\StaffManager;
use App\Support\DeliveryNotes\DeliveryNoteShare;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Remito en el panel: el documento completo (firmas, materiales, aclaraciones),
 * descarga del PDF y compartir (WhatsApp, correo, enlace, portal), sin
 * cambiar quién puede verlo.
 */
class AdminDeliveryNoteViewTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    private const SIGNATURE = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    private array $a;

    private array $b;

    private DeliveryNote $note;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->a = $this->makeTenant();
        $this->b = $this->makeTenant();
        Client::whereKey($this->a['building']->client_id)->update(['name' => 'Consorcio Arenales', 'email' => 'admin@arenales.test']);

        $this->actingAs($this->a['admin']);
        $order = WorkOrder::factory()->create(['building_id' => $this->a['building']->id, 'type' => 'claim', 'notes' => 'NOTA INTERNA SECRETA']);
        auth()->logout();

        $this->note = DeliveryNote::factory()->create([
            'company_id' => $this->a['company']->id, 'building_id' => $this->a['building']->id, 'user_id' => $this->a['technician']->id,
            'work_order_id' => $order->id, 'description' => 'Cambio de contactor y prueba de maniobra.',
            'signature' => self::SIGNATURE, 'signature_name' => 'Lucas Técnico', 'client_signature' => self::SIGNATURE, 'client_signature_name' => 'Marta Encargada',
        ]);
    }

    private function inPanel(User $user): void
    {
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('ascensores_app'));
    }

    public function test_the_admin_sees_the_whole_remito_with_both_signatures(): void
    {
        $this->inPanel($this->a['admin']);

        $this->get(DeliveryNoteResource::getUrl('view', ['record' => $this->note]))
            ->assertOk()
            ->assertSee('Remito #'.DeliveryNoteShare::number($this->note))
            ->assertSee('Consorcio Arenales')
            ->assertSee('Cambio de contactor y prueba de maniobra.')
            ->assertSee('Lucas Técnico')
            ->assertSee('Marta Encargada')
            ->assertSee(self::SIGNATURE, false)
            ->assertSee('Datos internos')
            ->assertSee('Descargar PDF')
            ->assertSee('Compartir')
            ->assertDontSee('NOTA INTERNA SECRETA');
    }

    public function test_the_pdf_downloads_as_a_file_or_opens_in_the_browser(): void
    {
        $this->inPanel($this->a['admin']);

        $download = $this->get(DeliveryNoteShare::pdfUrl($this->note, download: true))->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('attachment; filename="remito-', $download->headers->get('Content-Disposition'));

        $inline = $this->get(DeliveryNoteShare::pdfUrl($this->note))->assertOk();
        $this->assertStringStartsWith('inline;', $inline->headers->get('Content-Disposition'));
    }

    public function test_share_links_point_to_the_public_remito_of_this_note(): void
    {
        $public = DeliveryNoteShare::publicUrl($this->note);
        $this->assertStringContainsString($this->note->public_token, $public);
        $this->assertStringContainsString(rawurlencode($public), DeliveryNoteShare::whatsappUrl($this->note));
        $this->assertStringStartsWith('mailto:admin@arenales.test?subject=', DeliveryNoteShare::mailUrl($this->note));

        // Lo que recibe el cliente: el remito sin datos internos.
        auth()->logout();
        $this->get($public)->assertOk()->assertSee('Marta Encargada')->assertDontSee('NOTA INTERNA SECRETA');

        $this->inPanel($this->a['admin']);
        Livewire::test(ViewDeliveryNote::class, ['record' => $this->note->getRouteKey()])
            ->assertActionVisible('downloadPdf')
            ->assertActionHidden('createShareLink');
    }

    public function test_an_old_remito_without_link_gets_one_when_shared_without_touching_the_signed_data(): void
    {
        DB::table('delivery_notes')->where('id', $this->note->id)->update(['public_token' => null]);
        $before = DB::table('delivery_notes')->where('id', $this->note->id)->first();

        $this->inPanel($this->a['admin']);
        Livewire::test(ViewDeliveryNote::class, ['record' => $this->note->getRouteKey()])
            ->assertActionVisible('createShareLink')
            ->callAction('createShareLink')
            ->assertNotified('Enlace creado');

        $after = DB::table('delivery_notes')->where('id', $this->note->id)->first();
        $this->assertNotNull($after->public_token);
        foreach (['number', 'description', 'signature', 'client_signature', 'signature_name', 'client_signature_name', 'performed', 'updated_at'] as $field) {
            $this->assertEquals($before->$field, $after->$field, $field);
        }
    }

    public function test_the_admin_shows_it_in_the_portal_only_with_a_plan_that_includes_it(): void
    {
        $this->giveCompanyPlan($this->a['company'], 'inicial');
        $this->inPanel($this->a['admin']);
        Livewire::test(ViewDeliveryNote::class, ['record' => $this->note->getRouteKey()])->assertActionHidden('shareInPortal');

        $this->giveCompanyPlan($this->a['company'], 'empresa');
        Livewire::test(ViewDeliveryNote::class, ['record' => $this->note->getRouteKey()])
            ->callAction('shareInPortal')
            ->assertNotified('Compartido en el portal del cliente');
        $this->assertTrue((bool) $this->note->fresh()->shared_with_client);

        Livewire::test(ViewDeliveryNote::class, ['record' => $this->note->getRouteKey()])->callAction('unshareInPortal');
        $this->assertFalse((bool) $this->note->fresh()->shared_with_client);
    }

    public function test_the_list_offers_download_and_share_on_each_row(): void
    {
        $this->inPanel($this->a['admin']);

        Livewire::test(ListDeliveryNotes::class)
            ->assertTableActionVisible('pdf', $this->note)
            ->assertTableActionVisible('whatsapp', $this->note)
            ->assertTableActionVisible('mail', $this->note);
    }

    public function test_access_rules_do_not_change(): void
    {
        // Otra empresa: no lo ve ni descarga su PDF.
        $this->inPanel($this->b['admin']);
        $this->get(DeliveryNoteResource::getUrl('view', ['record' => $this->note]))->assertNotFound();
        $foreign = $this->get(DeliveryNoteShare::pdfUrl($this->note, download: true)); // lo manda a su empresa
        $this->assertNotSame(200, $foreign->status());
        $this->assertNotSame('application/pdf', $foreign->headers->get('Content-Type'));

        // Administrativo sin Documentos: tampoco. Con Documentos («ver»): sí.
        $this->actingAs($this->a['admin']);
        $this->giveCompanyPlan($this->a['company'], 'empresa');
        $noDocs = app(StaffManager::class)->create($this->a['admin'], ['name' => 'Sin documentos', 'email' => 'sindocs@a.test', 'password' => 'clave-segura-123'], ['clients' => 'view']);
        $docs = app(StaffManager::class)->create($this->a['admin'], ['name' => 'Con documentos', 'email' => 'docs@a.test', 'password' => 'clave-segura-123'], ['documents' => 'view']);

        $this->inPanel($noDocs);
        $this->get(DeliveryNoteResource::getUrl('view', ['record' => $this->note]))->assertForbidden();
        $this->assertNotSame('application/pdf', $this->get(DeliveryNoteShare::pdfUrl($this->note, download: true))->headers->get('Content-Type'));

        $this->inPanel($docs);
        $this->get(DeliveryNoteResource::getUrl('view', ['record' => $this->note]))->assertOk()->assertSee('Marta Encargada');
        $this->get(DeliveryNoteShare::pdfUrl($this->note, download: true))->assertOk();
    }
}
