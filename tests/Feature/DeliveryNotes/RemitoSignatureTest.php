<?php

namespace Tests\Feature\DeliveryNotes;

use App\Models\BuildingVisit;
use App\Models\Company;
use App\Models\DeliveryNote;
use App\Models\PhotoSection;
use App\Support\DeliveryNotes\SignatureImage;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Firma del remito, validada en el servidor: una imagen real con trazo,
 * re-codificada como PNG. Se conserva si el remito vuelve por otro error,
 * las fotos (que ahora viven en la visita) no la tocan y un reenvío no crea
 * otro remito.
 */
class RemitoSignatureTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    private array $a;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->travelTo(Carbon::parse('2026-10-14 09:00'));
        $this->a = $this->makeTenant();
    }

    private function sign(array $overrides = [])
    {
        return $this->actingAs($this->a['technician'])->post("/{$this->a['company']->slug}/delivery-notes/store", [
            'building_id' => $this->a['building']->id,
            'description' => 'Mantenimiento preventivo.',
            'month' => 10, 'year' => 2026,
            'elevator_quantity' => 2, 'freight_elevator_quantity' => 0,
            'assignment_type' => 'maintenance',
            'performed' => '1',
            'signature_name' => 'Técnico',
            'signature' => $this->validSignature(),
            ...$overrides,
        ]);
    }

    private function form()
    {
        return $this->actingAs($this->a['technician'])->get("/{$this->a['company']->slug}/delivery-notes/create/building/{$this->a['building']->id}?assignment_type=maintenance&month=10&year=2026");
    }

    public function test_a_valid_signature_is_captured_and_stored_as_a_clean_png(): void
    {
        $this->sign()->assertSessionHasNoErrors();

        $stored = DeliveryNote::sole()->signature;
        $this->assertMatchesRegularExpression(SignatureImage::PATTERN, $stored);
        $info = getimagesizefromstring(base64_decode(Str::after($stored, 'base64,')));
        $this->assertSame([600, 200, IMAGETYPE_PNG], [$info[0], $info[1], $info[2]]);
    }

    public function test_an_empty_signature_is_rejected(): void
    {
        // Un lienzo en blanco (transparente) o sin firma.
        $this->sign(['signature' => $this->signaturePng(false)])->assertSessionHasErrors(['signature' => 'La firma del técnico está vacía. Volvé a firmar.']);
        $this->sign(['signature' => ''])->assertSessionHasErrors('signature');
        // La firma del cliente es opcional, pero si viene no puede estar vacía.
        $this->sign(['client_signature' => $this->signaturePng(false)])->assertSessionHasErrors('client_signature');

        $this->assertSame(0, DeliveryNote::count());
        $this->assertSame(0, BuildingVisit::count());
    }

    public function test_a_manipulated_signature_is_never_stored(): void
    {
        // Lo que antes pasaba la regla: base64 que no es una imagen.
        $garbage = 'data:image/png;base64,'.str_repeat('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJ', 3);
        $this->sign(['signature' => $garbage])->assertSessionHasErrors(['signature' => 'La firma del técnico no es válida. Volvé a firmar.']);
        // Un enlace externo, otro tipo de dato o una imagen gigante.
        $this->sign(['signature' => 'https://evil.example/pixel.png'.str_repeat('a', 100)])->assertSessionHasErrors('signature');
        $this->sign(['signature' => 'data:image/svg+xml;base64,'.base64_encode('<svg onload="alert(1)"/>')])->assertSessionHasErrors('signature');
        $huge = imagecreatetruecolor(5000, 100);
        ob_start();
        imagepng($huge);
        $this->sign(['signature' => 'data:image/png;base64,'.base64_encode((string) ob_get_clean())])->assertSessionHasErrors('signature');
        $this->assertSame(0, DeliveryNote::count());

        // Una imagen real con datos agregados al final: se guarda re-codificada, sin el agregado.
        $payload = base64_decode(Str::after($this->validSignature(), 'base64,')).'<?php system($_GET["c"]); ?>';
        $this->sign(['signature' => 'data:image/png;base64,'.base64_encode($payload)])->assertSessionHasNoErrors();
        $this->assertStringNotContainsString('system(', base64_decode(Str::after(DeliveryNote::sole()->signature, 'base64,')));
    }

    public function test_the_signature_survives_another_validation_error(): void
    {
        $signature = $this->validSignature();
        $this->from("/{$this->a['company']->slug}/delivery-notes/create/building/{$this->a['building']->id}?assignment_type=maintenance&month=10&year=2026")
            ->sign(['description' => '', 'signature' => $signature])->assertSessionHasErrors('description');

        // El formulario vuelve con la firma cargada (no hay que firmar de nuevo).
        $this->form()->assertOk()->assertSee('id="sig-tech-input" value="'.$signature.'"', false);

        // Lo que no es una imagen válida nunca se vuelve a imprimir en la página.
        $this->sign(['description' => '', 'signature' => 'javascript:alert(1)'])->assertSessionHasErrors();
        $this->form()->assertOk()->assertSee('id="sig-tech-input" value=""', false)->assertDontSee('javascript:alert');
    }

    public function test_photos_live_in_the_visit_and_never_touch_the_signature_form(): void
    {
        Storage::fake('local');
        $this->a['company']->forceFill(['photos_maintenance' => true])->save();
        $this->actingAs($this->a['technician'])->post("/{$this->a['company']->slug}/visits/start", ['building_id' => $this->a['building']->id, 'assignment_type' => 'maintenance', 'month' => 10, 'year' => 2026]);
        $visit = BuildingVisit::withoutGlobalScope('completed')->sole();
        PhotoSection::forCompany($this->a['company']->id);
        $this->actingAs($this->a['technician'])->postJson("/{$this->a['company']->slug}/visit-photos", [
            'visit_id' => $visit->id, 'photo_section_id' => PhotoSection::withoutGlobalScopes()->where('company_id', $this->a['company']->id)->first()->id,
            'stage' => 'before', 'photo' => UploadedFile::fake()->image('a.jpg'),
        ])->assertCreated();

        // El formulario del remito no tiene cargas de fotos ni bloqueos por fotos pendientes.
        $this->form()->assertOk()->assertDontSee('data-file aria-label', false)->assertDontSee('ascentoVisitPhotos')->assertSee('sig-modal-canvas', false);

        // Firmar con fotos ya cargadas: la firma queda intacta y la visita se completa.
        $signature = $this->validSignature();
        $this->sign(['signature' => $signature])->assertSessionHasNoErrors();
        $this->assertSame($visit->id, DeliveryNote::sole()->building_visit_id);
        $this->assertMatchesRegularExpression(SignatureImage::PATTERN, DeliveryNote::sole()->signature);
    }

    public function test_retries_never_create_a_second_remito(): void
    {
        $token = (string) Str::uuid();
        $this->sign(['submission_token' => $token])->assertSessionHasNoErrors();
        $this->sign(['submission_token' => $token])->assertSessionHasNoErrors()->assertSessionHas('success');

        $this->assertSame(1, DeliveryNote::count());
        $this->assertSame(1, BuildingVisit::count());
    }

    public function test_the_pad_bundle_has_a_neutral_name_and_the_form_warns_if_it_does_not_load(): void
    {
        $this->form()->assertOk()->assertSee('No se pudo cargar el recuadro de firma', false);
        $this->assertFileExists(resource_path('js/remito-pad.js'));
        $this->assertFileDoesNotExist(resource_path('js/signature.js'));
        $this->assertStringContainsString("'resources/js/remito-pad.js'", file_get_contents(base_path('vite.config.js')));
        $this->assertFalse(Company::find($this->a['company']->id)->photos_maintenance);
    }
}
