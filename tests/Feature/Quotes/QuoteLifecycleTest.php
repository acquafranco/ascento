<?php

namespace Tests\Feature\Quotes;

use App\Exceptions\PlanFeatureUnavailableException;
use App\Filament\Resources\Quotes\Pages\ViewQuote;
use App\Filament\Resources\Quotes\QuoteResource;
use App\Models\Quote;
use App\Models\QuoteEvent;
use App\Models\Receivable;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Notifications\QuoteSentNotification;
use App\Services\Billing\ReceivableService;
use App\Services\Quotes\QuoteSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Presupuesto de punta a punta: número, envío, enlace firmado, PDF, estados,
 * duplicado y su relación con las cobranzas (nunca automática).
 */
class QuoteLifecycleTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    private array $a;

    private array $b;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->a = $this->makeTenant();
        $this->b = $this->makeTenant();
        $this->a['building']->client->update(['email' => 'consorcio@cliente.test', 'phone' => '11 5555-1234']);
        auth()->logout();
    }

    private function onPlan(string $slug, ?array $tenant = null): void
    {
        $tenant ??= $this->a;
        $plan = SubscriptionPlan::findBySlug($slug);
        Subscription::updateOrCreate(['company_id' => $tenant['company']->id], [
            'provider' => 'mercadopago', 'provider_subscription_id' => 'PRE-'.$tenant['company']->id, 'plan' => $slug,
            'status' => Subscription::AUTHORIZED, 'amount' => $plan->price, 'currency' => 'ARS', 'current_period_end' => now()->addMonth(),
        ]);
        $tenant['company']->forgetPlan();
    }

    private function quote(array $tenant, array $attrs = []): Quote
    {
        $this->actingAs($tenant['admin']);
        $quote = Quote::create([
            'building_id' => $tenant['building']->id, 'client_id' => $tenant['building']->client_id, 'created_by' => $tenant['admin']->id,
            'title' => 'Cambio de operador de puertas', 'description' => 'Reemplazo completo del operador.', 'conditions' => '50% de anticipo.',
            'notes' => 'NOTA INTERNA: margen 30%', 'valid_until' => now()->addDays(15)->toDateString(), ...$attrs,
        ]);
        $quote->items()->create(['concept' => 'Operador', 'quantity' => 1, 'unit_price' => 250000]);
        $quote->items()->create(['concept' => 'Mano de obra', 'quantity' => 2, 'unit_price' => 30000]);
        $quote->refreshTotal();
        auth()->logout();

        return $quote->fresh();
    }

    public function test_numbers_are_sequential_per_company(): void
    {
        $a1 = $this->quote($this->a);
        $a2 = $this->quote($this->a);
        $b1 = $this->quote($this->b);

        $this->assertSame([1, 2, 1], [$a1->number, $a2->number, $b1->number]);
        $this->assertSame('P-000002', $a2->numberLabel());
        $this->assertSame('Creado', QuoteEvent::LABELS[$a1->events()->first()->action]);
    }

    public function test_sending_mails_the_client_a_signed_link_and_the_pdf_and_records_it(): void
    {
        Notification::fake();
        $quote = $this->quote($this->a);

        $this->assertTrue(app(QuoteSender::class)->send($quote, 'consorcio@cliente.test', 'Quedamos atentos.', $this->a['admin']));

        $quote->refresh();
        $this->assertSame([Quote::SENT, 'consorcio@cliente.test'], [$quote->status, $quote->sent_to]);
        $this->assertNotNull($quote->sent_at);
        $this->assertSame(['created', 'status', 'sent'], $quote->events()->pluck('action')->all());

        Notification::assertSentOnDemand(QuoteSentNotification::class, function ($n, $channels, AnonymousNotifiable $to) use ($quote) {
            $mail = $n->toMail($to);
            $body = implode(' ', $mail->introLines);

            return $to->routes['mail'] === 'consorcio@cliente.test'
                && str_contains($mail->subject, $quote->numberLabel())
                && str_contains($body, '$ 310.000,00') && str_contains($body, 'Quedamos atentos.')
                && str_contains($mail->actionUrl, 'signature=') && str_contains($mail->actionUrl, 'expires=')
                && count($mail->rawAttachments) === 1 && str_starts_with($mail->rawAttachments[0]['data'], '%PDF')
                && ! str_contains($body, 'NOTA INTERNA');
        });
    }

    public function test_only_open_quotes_can_be_sent_and_only_on_plans_with_quotes(): void
    {
        $approved = $this->quote($this->a, ['status' => Quote::APPROVED]);
        $this->expectExceptionOnSend($approved);

        $this->onPlan('inicial');
        $draft = $this->quote($this->a);
        $this->expectException(PlanFeatureUnavailableException::class);
        app(QuoteSender::class)->send($draft->fresh(), 'x@y.test', null);
    }

    private function expectExceptionOnSend(Quote $quote): void
    {
        try {
            app(QuoteSender::class)->send($quote, 'x@y.test', null);
            $this->fail('Se envió un presupuesto cerrado.');
        } catch (ValidationException) {
            $this->assertSame(Quote::APPROVED, $quote->fresh()->status);
        }
    }

    public function test_client_links_are_signed_expire_and_can_be_revoked(): void
    {
        $quote = $this->quote($this->a, ['status' => Quote::SENT]);
        $link = $quote->signedPublicUrl();

        $this->get($link)->assertOk()->assertSee('Cambio de operador de puertas')->assertSee('$ 310.000,00')
            ->assertSee('50% de anticipo.')->assertDontSee('NOTA INTERNA')->assertSee('signature=', false);

        // Sin firma, firma alterada, vencido.
        $this->get(strtok($link, '?'))->assertStatus(410)->assertSee('Este enlace ya no está disponible');
        $this->get(preg_replace('/signature=[a-f0-9]+/', 'signature='.str_repeat('0', 64), $link))->assertStatus(410);
        $this->travel(200)->days();
        $this->get($link)->assertStatus(410);
        $this->travelBack();

        // Borrador o anulado: el enlace no muestra nada.
        $quote->update(['status' => Quote::VOID]);
        $this->get($link)->assertStatus(410);

        // Anular enlaces: el anterior deja de servir; uno nuevo sí.
        $open = $this->quote($this->a, ['status' => Quote::SENT]);
        $old = $open->signedPublicUrl();
        app(QuoteSender::class)->revokeLinks($open, $this->a['admin']);
        $this->get($old)->assertStatus(410);
        $this->get($open->fresh()->signedPublicUrl())->assertOk();
    }

    public function test_pdf_access_is_checked_on_the_server(): void
    {
        $quote = $this->quote($this->a, ['status' => Quote::SENT]);

        $this->actingAs($this->a['admin'])->get(route('quotes.pdf', $quote))->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->actingAs($this->a['technician'])->get(route('quotes.pdf', $quote))->assertNotFound();
        $this->actingAs($this->b['admin'])->get(route('quotes.pdf', $quote))->assertNotFound();
        auth()->logout();
        $this->get(route('quotes.public.pdf', ['company' => $this->a['company']->slug, 'token' => $quote->public_token]))->assertStatus(410);

        // Portal: solo si se compartió y el edificio está autorizado.
        $portal = User::factory()->create();
        $portal->forceFill(['role' => User::ROLE_CLIENT, 'company_id' => $this->a['company']->id, 'client_id' => $this->a['building']->client_id])->save();
        $portal->portalBuildings()->sync([$this->a['building']->id]);
        $this->actingAs($portal)->get(route('portal.quote-pdf', $quote))->assertNotFound();
        $quote->shareWithClient(true);
        $this->get(route('portal.quote-pdf', $quote))->assertOk();
        $this->get(route('portal.quote', $quote))->assertOk()->assertSee($quote->numberLabel())->assertDontSee('NOTA INTERNA');
    }

    public function test_closed_quotes_cannot_be_edited_and_transitions_follow_the_flow(): void
    {
        $quote = $this->quote($this->a);
        $this->assertTrue($quote->isEditable());
        $this->assertSame([Quote::SENT, Quote::APPROVED, Quote::REJECTED, Quote::VOID], $quote->allowedTransitions());

        foreach ([Quote::APPROVED, Quote::REJECTED, Quote::VOID] as $status) {
            $quote->update(['status' => $status]);
            $this->assertFalse(QuoteResource::canEdit($quote->fresh()), "Editable en {$status}");
        }
        $this->assertSame([], $quote->fresh()->allowedTransitions()); // anulado: final

        $this->actingInPanel($this->a['admin']);
        $this->get(QuoteResource::getUrl('edit', ['record' => $quote]))->assertForbidden();
        Livewire::test(ViewQuote::class, ['record' => $quote->getRouteKey()])
            ->assertActionHidden('edit')->assertActionHidden('send')->assertActionVisible('duplicate');
    }

    public function test_duplicate_creates_a_new_open_draft_with_the_same_items(): void
    {
        $quote = $this->quote($this->a, ['status' => Quote::APPROVED]);

        $this->actingInPanel($this->a['admin']);
        Livewire::test(ViewQuote::class, ['record' => $quote->getRouteKey()])->callAction('duplicate');

        $copy = Quote::latest('id')->first();
        $this->assertNotSame($quote->id, $copy->id);
        $this->assertSame([Quote::DRAFT, 2], [$copy->status, $copy->number]);
        $this->assertEquals($quote->amount, $copy->amount);
        $this->assertSame(['Operador', 'Mano de obra'], $copy->items->pluck('concept')->all());
        $this->assertNotSame($quote->public_token, $copy->public_token);
    }

    public function test_quotes_never_create_receivables_by_themselves_and_billing_is_once(): void
    {
        $quote = $this->quote($this->a);
        app(QuoteSender::class)->send($quote, 'consorcio@cliente.test', null);
        $quote->update(['status' => Quote::REJECTED]);
        $other = $this->quote($this->a, ['status' => Quote::SENT, 'valid_until' => now()->subDay()->toDateString()]);
        $this->assertTrue($other->isExpired());
        $approved = $this->quote($this->a);
        $approved->update(['status' => Quote::APPROVED]);

        $this->assertSame(0, Receivable::withoutGlobalScopes()->count()); // crear, enviar, rechazar, vencer, aprobar: nada se cobra solo

        // "Generar cobro": solo del aprobado, una vez, por el importe indicado.
        $receivable = app(ReceivableService::class)->fromQuote($approved, 'Presupuesto '.$approved->numberLabel(), (float) $approved->amount, now()->addDays(10), $this->a['admin']);
        $this->assertEquals(310000, $receivable->amount);
        $this->assertSame($approved->id, $receivable->quote_id);
        $this->expectException(ValidationException::class);
        app(ReceivableService::class)->fromQuote($approved->fresh(), 'Otra vez', 310000, now(), $this->a['admin']);
    }

    public function test_an_approved_quote_with_an_active_receivable_cannot_be_voided(): void
    {
        $quote = $this->quote($this->a);
        $quote->update(['status' => Quote::APPROVED]);
        app(ReceivableService::class)->fromQuote($quote, 'X', 310000, now(), $this->a['admin']);

        $this->assertSame([], $quote->fresh()->allowedTransitions());
        $this->actingInPanel($this->a['admin']);
        Livewire::test(ViewQuote::class, ['record' => $quote->getRouteKey()])->assertActionHidden('void');
    }
}
