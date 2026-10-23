<?php

namespace Tests\Feature\DeliveryNotes;

use App\Models\DeliveryNote;
use App\Models\Report;
use App\Models\WorkOrder;
use App\Services\Parachute\ParachuteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Concerns\InteractsWithTenants;
use Tests\Concerns\MakesSignatures;
use Tests\TestCase;

/**
 * El remito dice qué falta: cada campo con error se marca y lleva su mensaje,
 * los errores del servidor vuelven al formulario, un error inesperado se
 * explica (y se registra) sin guardar nada a medias, y lo correcto se confirma.
 * Las validaciones no se relajan.
 */
class RemitoValidationFeedbackTest extends TestCase
{
    use InteractsWithTenants, MakesSignatures, RefreshDatabase;

    private array $a;

    private WorkOrder $order;

    private Report $job;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');
        $this->a = $this->makeTenant();
        auth()->logout();
        $this->actingAs($this->a['technician'])->post(route('interventions.store', ['company' => $this->slug()]), [
            'building_id' => $this->a['building']->id, 'work_type' => 'repair', 'elevator_number' => 'Ascensor 1', 'channel' => 'whatsapp',
            'kind' => 'urgent', 'description' => 'No baja del piso 5.', 'submission_token' => (string) Str::uuid(),
        ])->assertRedirect();
        $this->order = WorkOrder::withoutGlobalScopes()->sole();
        $this->job = Report::withoutGlobalScopes()->sole();
    }

    private function slug(): string
    {
        return $this->a['company']->slug;
    }

    private function form()
    {
        return $this->get(route('delivery-notes.work-order', ['company' => $this->slug(), 'workOrder' => $this->order->id]));
    }

    private function submit(array $overrides = [])
    {
        return $this->from(route('delivery-notes.work-order', ['company' => $this->slug(), 'workOrder' => $this->order->id]))
            ->post("/{$this->slug()}/delivery-notes/store", [
                'building_id' => $this->order->building_id, 'work_order_id' => $this->order->id, 'assignment_type' => 'work_order',
                'elevator_quantity' => 2, 'freight_elevator_quantity' => 0, 'signature_name' => 'Lucas', 'signature' => $this->validSignature(),
                'jobs' => [$this->job->id => ['work_done' => 'Contactor cambiado.', 'result' => 'resolved', 'elevator_state' => 'in_service']],
                ...$overrides,
            ]);
    }

    public function test_the_form_validates_itself_and_marks_each_required_field(): void
    {
        $html = $this->form()->assertOk()->getContent();

        // La valida el formulario (no el navegador, que en el celular a veces no avisa nada).
        $this->assertMatchesRegularExpression('/<form[^>]*id="remito-form"[^>]*novalidate/', $html);
        foreach (['jobs.'.$this->job->id.'.work_done', 'jobs.'.$this->job->id.'.result', 'signature', 'signature_name', 'description'] as $field) {
            $this->assertStringContainsString('data-field="'.$field.'"', $html, $field);
        }
        $this->assertStringContainsString('data-required-message="Contá qué hiciste en el trabajo 1."', $html);
        $this->assertStringContainsString('function collectProblems()', $html);
        $this->assertStringContainsString("btn.setAttribute('aria-busy', 'true')", $html);
    }

    public function test_server_errors_come_back_marked_on_their_fields_and_nothing_is_saved(): void
    {
        $this->form();
        // Falta la firma y lo hecho y el resultado del trabajo.
        $this->submit(['signature' => '', 'jobs' => [$this->job->id => ['work_done' => '', 'result' => '']]])
            ->assertRedirect(route('delivery-notes.work-order', ['company' => $this->slug(), 'workOrder' => $this->order->id]))
            ->assertSessionHasErrors(['signature', 'jobs.'.$this->job->id.'.work_done', 'jobs.'.$this->job->id.'.result']);
        $this->assertSame(0, DeliveryNote::withoutGlobalScopes()->count());
        $this->assertSame('in_progress', $this->order->fresh()->status);

        // Al volver, la lista dice qué falta, la firma está marcada y cada error va a su campo.
        $html = $this->followingRedirects()->submit(['signature' => '', 'jobs' => [$this->job->id => ['work_done' => '', 'result' => '']]])->assertOk()->getContent();
        $this->assertStringContainsString('Revisá los campos marcados en rojo', $html);
        $this->assertMatchesRegularExpression('/<p id="tech-error" class="t-field-error ">Debe realizar la firma del técnico\.<\/p>/', $html);
        $this->assertStringContainsString('"jobs.'.$this->job->id.'.work_done":', $html);
        $this->assertStringContainsString('Contá qué hiciste en el trabajo 1.', $html);
    }

    public function test_a_complete_remito_is_generated_signed_and_confirmed(): void
    {
        $this->submit(['client_signature' => $this->validSignature(), 'client_signature_name' => 'Marta'])
            ->assertSessionHasNoErrors()->assertRedirect(route('delivery-notes.index', ['company' => $this->slug()]));

        $note = DeliveryNote::withoutGlobalScopes()->sole();
        $this->assertSame([$this->order->id, 'Marta'], [$note->work_order_id, $note->client_signature_name]);
        $this->assertNotNull($note->signature);
        $this->get(route('delivery-notes.index', ['company' => $this->slug()]))->assertSee('Remito generado correctamente.');
        $this->assertSame('completed', $this->order->fresh()->status);
    }

    public function test_an_unexpected_error_is_explained_logged_and_leaves_nothing_half_saved(): void
    {
        Log::spy();
        $this->mock(ParachuteService::class, function ($mock) {
            $mock->shouldReceive('registrableInDeliveryNote')->andReturn(collect());
            $mock->shouldReceive('recordFromDeliveryNote')->andThrow(new \RuntimeException('SQLSTATE[HY000] detalle interno'));
        });

        $response = $this->submit();
        $response->assertRedirect()->assertSessionHasErrors('general');
        $this->assertStringContainsString('No se pudo generar el remito por un problema inesperado', session('errors')->first('general'));
        $this->assertStringNotContainsString('SQLSTATE', session('errors')->first('general'));

        // Nada a medias: ni remito, ni visita, ni orden completada, ni trabajos cerrados.
        $this->assertSame(0, DeliveryNote::withoutGlobalScopes()->count());
        $this->assertSame('in_progress', $this->order->fresh()->status);
        $this->assertNull($this->job->fresh()->work_done);
        // Las firmas no se guardan en la sesión; lo demás vuelve cargado.
        $this->assertNull(session()->getOldInput('signature'));
        $this->assertSame('Lucas', session()->getOldInput('signature_name'));

        Log::shouldHaveReceived('error')->withArgs(fn ($message, $context) => $message === 'No se pudo generar un remito'
            && $context['work_order_id'] == $this->order->id && ! array_key_exists('signature', $context))->once();
    }

    public function test_a_remito_for_an_order_that_already_has_one_explains_why(): void
    {
        $this->submit()->assertSessionHasNoErrors();
        $this->submit()->assertStatus(409)->assertSee('Esta orden de trabajo ya tiene un remito.');
        $this->assertSame(1, DeliveryNote::withoutGlobalScopes()->count());
    }
}
