<?php

namespace Tests\Feature\DeliveryNotes;

use App\Http\Controllers\DeliveryNoteController;
use App\Models\Building;
use App\Models\DeliveryNote;
use App\Models\StockItem;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderMaterial;
use App\Support\DeliveryNotes\DeliveryNoteDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Remito rediseñado (técnico, portal, enlace público) y PDF real: mismos
 * datos en todos, solo lo que puede ver el cliente, permisos de siempre.
 */
class DeliveryNoteDocumentTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    private const SIGNATURE = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    private array $a;

    private array $b;

    private DeliveryNote $note;

    private WorkOrder $order;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->a = $this->makeTenant();
        $this->b = $this->makeTenant();
        $this->a['company']->forceFill(['name' => 'Ascensores Norte', 'primary_color' => '#0057B8'])->save();

        $this->actingAs($this->a['admin']);
        $item = StockItem::create(['name' => 'Contactor trifásico', 'unit' => 'unidad', 'cost' => 45678, 'min_stock' => 0, 'is_active' => true]);
        $this->order = WorkOrder::factory()->create(['building_id' => $this->a['building']->id, 'type' => 'claim', 'unit' => 'Ascensor 2', 'notes' => 'NOTA INTERNA SECRETA: cobrar extra']);
        $this->order->participants()->attach($this->a['technician']->id, ['role' => 'creator']);
        WorkOrderMaterial::create(['work_order_id' => $this->order->id, 'stock_item_id' => $item->id, 'quantity' => 2]);
        auth()->logout();

        $this->note = DeliveryNote::factory()->create([
            'company_id' => $this->a['company']->id, 'building_id' => $this->a['building']->id, 'user_id' => $this->a['technician']->id,
            'work_order_id' => $this->order->id, 'description' => "Cambio de contactor.\nPrueba de maniobra OK.",
            'signature' => self::SIGNATURE, 'signature_name' => 'Lucas Técnico', 'client_signature' => self::SIGNATURE, 'client_signature_name' => 'Marta Encargada',
        ]);
        $this->note->forceFill(['shared_with_client' => true])->save();
    }

    private function slug(): string
    {
        return $this->a['company']->slug;
    }

    /** HTML de la plantilla del PDF (el binario de dompdf viene comprimido). */
    private function pdfHtml(DeliveryNote $note): string
    {
        $note->load(DeliveryNoteController::RELATIONS);

        return view('delivery-notes.pdf', ['doc' => DeliveryNoteDocument::for($note, pdf: true)])->render();
    }

    private function portalUser(array $buildingIds): User
    {
        $user = User::factory()->create(['email' => 'cliente'.uniqid().'@consorcio.test']);
        $user->forceFill(['role' => User::ROLE_CLIENT, 'company_id' => $this->a['company']->id, 'client_id' => $this->a['building']->client_id])->save();
        $user->portalBuildings()->sync($buildingIds);

        return $user;
    }

    private function assertClientSafe(string $html): void
    {
        $this->assertStringNotContainsString('NOTA INTERNA SECRETA', $html, 'observaciones internas de la orden');
        $this->assertStringNotContainsString('45.678', $html, 'costo del material');
        $this->assertStringNotContainsString('45678', $html);
    }

    // --- Vista del técnico ------------------------------------------------------

    public function test_the_technician_sees_the_redesigned_note_with_all_its_data(): void
    {
        $html = $this->actingAs($this->a['technician'])->get("/{$this->slug()}/delivery-notes/{$this->note->number}")->assertOk()
            ->assertSee('Remito #'.str_pad((string) $this->note->number, 6, '0', STR_PAD_LEFT))
            ->assertSee('Ascensores Norte')
            ->assertSee('background: #0057B8', false)
            ->assertSee('Orden de trabajo: Reclamo')
            ->assertSee('Ascensor 2')
            ->assertSee($this->a['building']->name)
            ->assertSee($this->a['technician']->name)
            ->assertSee('Prueba de maniobra OK.')
            ->assertSee('Contactor trifásico')
            ->assertSee('(Responsable)')
            ->assertSee('Lucas Técnico')
            ->assertSee('Marta Encargada')
            ->assertSee('src="'.self::SIGNATURE.'"', false)
            ->assertSee('✓ Realizado')
            ->getContent();

        $this->assertClientSafe($html);
        // "Descargar PDF" del técnico = el PDF del enlace público (no la ruta de admin).
        $this->assertStringContainsString(route('delivery-notes.public.pdf', ['company' => $this->slug(), 'token' => $this->note->public_token]), $html);
    }

    public function test_another_technician_or_company_cannot_open_it(): void
    {
        $other = User::factory()->technician()->create(['company_id' => $this->a['company']->id]);

        $this->actingAs($other)->get("/{$this->slug()}/delivery-notes/{$this->note->number}")->assertNotFound();
        $this->actingAs($this->b['admin'])->get("/{$this->slug()}/delivery-notes/{$this->note->number}")->assertNotFound();
    }

    // --- PDF real ---------------------------------------------------------------

    public function test_the_admin_downloads_a_real_pdf(): void
    {
        $res = $this->actingAs($this->a['admin'])->get("/{$this->slug()}/delivery-notes/{$this->note->number}/pdf")->assertOk();

        $this->assertSame('application/pdf', $res->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF', $res->getContent());
        $this->assertStringContainsString('remito-'.str_pad((string) $this->note->number, 6, '0', STR_PAD_LEFT).'.pdf', $res->headers->get('Content-Disposition'));
        $this->assertStringContainsString('no-store', $res->headers->get('Cache-Control'));
    }

    public function test_the_pdf_template_has_the_note_data_brand_and_signatures_only(): void
    {
        $html = $this->pdfHtml($this->note);

        foreach (['Ascensores Norte', 'background: #0057B8', 'Orden de trabajo: Reclamo', 'Cambio de contactor.', 'Contactor trifásico', 'Lucas Técnico', 'Marta Encargada', 'Página', 'Emitido con Ascento'] as $text) {
            $this->assertStringContainsString($text, $html);
        }
        $this->assertSame(2, substr_count($html, 'src="'.self::SIGNATURE.'"'));
        $this->assertStringNotContainsString('display: flex', $html, 'dompdf no entiende flex');
        $this->assertClientSafe($html);
    }

    public function test_pdf_permissions_are_unchanged(): void
    {
        $url = "/{$this->slug()}/delivery-notes/{$this->note->number}/pdf";

        // Técnicos: la ruta autenticada sigue siendo solo de admins (comparten el PDF del enlace público).
        $this->actingAs($this->a['technician'])->get($url)->assertNotFound();
        // Otra empresa: nada.
        $this->actingAs($this->b['admin'])->get($url)->assertNotFound();
        $this->actingAs($this->b['admin'])->get("/{$this->b['company']->slug}/delivery-notes/{$this->note->number}/pdf")->assertNotFound();
    }

    public function test_the_pdf_requires_the_digital_delivery_notes_feature(): void
    {
        $plan = SubscriptionPlan::findBySlug('inicial');
        Subscription::updateOrCreate(['company_id' => $this->a['company']->id], [
            'provider' => 'mercadopago', 'provider_subscription_id' => 'PRE-X', 'plan' => 'inicial',
            'status' => Subscription::AUTHORIZED, 'amount' => $plan->price, 'currency' => 'ARS', 'current_period_end' => now()->addMonth(),
        ]);
        $this->a['company']->forgetPlan();
        $this->a['company']->forceFill(['trial_ends_at' => now()->subDay()])->save();

        $res = $this->actingAs($this->a['admin'])->get("/{$this->slug()}/delivery-notes/{$this->note->number}/pdf");
        $this->assertNotSame('application/pdf', $res->headers->get('Content-Type'));
    }

    // --- Enlace público -----------------------------------------------------------

    public function test_the_public_link_shows_the_note_and_its_pdf_without_company_actions(): void
    {
        $page = $this->get(route('delivery-notes.public', ['company' => $this->slug(), 'token' => $this->note->public_token]))->assertOk()
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->assertSee('Marta Encargada')
            ->assertSee('Descargar PDF')
            ->assertDontSee('Compartir remito')
            ->assertDontSee('id="btn-share"', false);
        $this->assertClientSafe($page->getContent());

        $pdf = $this->get(route('delivery-notes.public.pdf', ['company' => $this->slug(), 'token' => $this->note->public_token]))->assertOk();
        $this->assertStringStartsWith('%PDF', $pdf->getContent());

        // Token inventado o de otra empresa: nada.
        $this->get(route('delivery-notes.public.pdf', ['company' => $this->slug(), 'token' => 'inventado']))->assertNotFound();
        $this->get(route('delivery-notes.public.pdf', ['company' => $this->b['company']->slug, 'token' => $this->note->public_token]))->assertNotFound();
    }

    // --- Portal -------------------------------------------------------------------

    public function test_the_portal_shows_its_own_view_and_pdf_for_shared_notes(): void
    {
        $client = $this->portalUser([$this->a['building']->id]);

        $html = $this->actingAs($client)->get(route('portal.delivery-note', $this->note))->assertOk()
            ->assertSee('Remito #')
            ->assertSee('Marta Encargada')
            ->assertSee(route('portal.delivery-note-pdf', $this->note), false)
            ->assertDontSee('Compartir')
            ->assertDontSee('Ver planes')
            ->getContent();
        $this->assertClientSafe($html);

        $pdf = $this->get(route('portal.delivery-note-pdf', $this->note))->assertOk();
        $this->assertStringStartsWith('%PDF', $pdf->getContent());
    }

    public function test_the_portal_never_shows_notes_that_are_not_shared_or_from_other_buildings(): void
    {
        $authorized = $this->portalUser([$this->a['building']->id]);
        $otherBuilding = Building::factory()->create(['company_id' => $this->a['company']->id, 'client_id' => $this->a['building']->client_id]);
        $outsider = $this->portalUser([$otherBuilding->id]);

        $this->actingAs($outsider)->get(route('portal.delivery-note', $this->note))->assertNotFound();
        $this->get(route('portal.delivery-note-pdf', $this->note))->assertNotFound();

        $this->note->forceFill(['shared_with_client' => false])->save();
        $this->actingAs($authorized)->get(route('portal.delivery-note', $this->note))->assertNotFound();
        $this->get(route('portal.delivery-note-pdf', $this->note))->assertNotFound();
    }

    // --- Datos de empresa ---------------------------------------------------------

    public function test_brand_color_is_validated_and_text_stays_readable(): void
    {
        $this->assertSame('#11161D', DeliveryNoteDocument::color('rojo; background:url(x)'));
        $this->assertSame('#11161D', DeliveryNoteDocument::color(null));
        $this->assertSame('#FFFFFF', DeliveryNoteDocument::readableOn('#0057B8'));
        $this->assertSame('#11161D', DeliveryNoteDocument::readableOn('#FFE066'));

        $this->a['company']->forceFill(['primary_color' => '#FFE066'])->save();
        $this->actingAs($this->a['technician'])->get("/{$this->slug()}/delivery-notes/{$this->note->number}")
            ->assertSee('background: #FFE066; color: #11161D', false);
    }

    public function test_the_company_logo_is_embedded_in_the_pdf_and_linked_on_the_web(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('companies/logos/norte.png', base64_decode(substr(self::SIGNATURE, strlen('data:image/png;base64,'))));
        $this->a['company']->forceFill(['logo' => 'companies/logos/norte.png'])->save();

        $this->assertStringContainsString('src="data:image/png;base64,', $this->pdfHtml($this->note->fresh()));
        $this->actingAs($this->a['technician'])->get("/{$this->slug()}/delivery-notes/{$this->note->number}")
            ->assertSee(asset('storage/companies/logos/norte.png'), false);

        // Un logo que no existe o con ruta rara: sin logo, sin error.
        $this->a['company']->forceFill(['logo' => '../../.env'])->save();
        $this->assertStringNotContainsString('data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==" style="max-width: 52px', $this->pdfHtml($this->note->fresh()));
    }

    public function test_a_long_description_is_kept_whole_in_web_and_pdf(): void
    {
        $long = implode("\n", array_map(fn ($i) => "Paso {$i}: revisión y ajuste completo del componente número {$i}.", range(1, 120)));
        $this->note->forceFill(['description' => $long])->save();

        $this->actingAs($this->a['technician'])->get("/{$this->slug()}/delivery-notes/{$this->note->number}")->assertSee('Paso 120:');
        $html = $this->pdfHtml($this->note->fresh());
        $this->assertStringContainsString('Paso 120:', $html);

        // Y dompdf lo pagina sin errores.
        $res = $this->actingAs($this->a['admin'])->get("/{$this->slug()}/delivery-notes/{$this->note->number}/pdf")->assertOk();
        $this->assertStringStartsWith('%PDF', $res->getContent());
    }

    // --- Revisión de seguridad --------------------------------------------------

    public function test_a_logo_value_can_never_embed_another_server_file(): void
    {
        Storage::fake('public');
        // Un archivo que NO es imagen, aunque se llame .png, en el disco público.
        Storage::disk('public')->put('companies/logos/falso.png', 'SECRETO-NO-ES-IMAGEN');

        foreach (['../../.env', '/etc/passwd', 'file:///etc/passwd', 'companies/../../.env', 'companies/logos/falso.png', "companies/logos/x.png\0.jpg"] as $value) {
            $this->a['company']->forceFill(['logo' => $value])->save();
            $html = $this->pdfHtml($this->note->fresh());

            $this->assertStringNotContainsString('max-width: 52px', $html, "no hay logo para «{$value}»");
            $this->assertStringNotContainsString(base64_encode('SECRETO-NO-ES-IMAGEN'), $html);
            $this->actingAs($this->a['technician'])->get("/{$this->slug()}/delivery-notes/{$this->note->number}")
                ->assertDontSee('class="rm-logo"', false);
        }
    }

    public function test_a_note_without_public_token_still_opens_without_share_or_plan_notice(): void
    {
        DB::table('delivery_notes')->where('id', $this->note->id)->update(['public_token' => null]);

        $this->actingAs($this->a['technician'])->get("/{$this->slug()}/delivery-notes/{$this->note->number}")->assertOk()
            ->assertSee('Marta Encargada')
            ->assertDontSee('id="btn-share"', false)
            ->assertDontSee('data-plan-locked', false);
    }

    public function test_portal_users_of_another_company_cannot_reach_the_note_by_its_number(): void
    {
        $foreignClient = User::factory()->create(['email' => 'ajeno@otro.test']);
        $foreignClient->forceFill(['role' => User::ROLE_CLIENT, 'company_id' => $this->b['company']->id, 'client_id' => $this->b['building']->client_id])->save();
        $foreignClient->portalBuildings()->sync([$this->b['building']->id]);

        $this->actingAs($foreignClient)->get(route('portal.delivery-note', $this->note))->assertNotFound();
        $this->get(route('portal.delivery-note-pdf', $this->note))->assertNotFound();
    }

    public function test_the_pdf_file_itself_carries_no_internal_data(): void
    {
        $raw = $this->actingAs($this->a['admin'])->get("/{$this->slug()}/delivery-notes/{$this->note->number}/pdf")->assertOk()->getContent();

        // Metadatos y texto sin comprimir del archivo.
        $this->assertStringNotContainsString('NOTA INTERNA', $raw);
        $this->assertStringNotContainsString('45678', $raw);
        $this->assertStringNotContainsString((string) $this->a['technician']->email, $raw);
    }
}
