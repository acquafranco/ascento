<?php

namespace Tests\Feature\Quotes;

use App\Filament\Resources\Quotes\Pages\CreateQuote;
use App\Filament\Resources\Quotes\Pages\EditQuote;
use App\Filament\Resources\Quotes\Pages\ListQuotes;
use App\Filament\Resources\Quotes\Pages\ViewQuote;
use App\Filament\Resources\Quotes\QuoteResource;
use App\Filament\Widgets\AdminStats;
use App\Models\DeliveryNote;
use App\Models\Quote;
use App\Models\QuoteItem;
use App\Models\Receivable;
use App\Models\Subscription;
use App\Services\Billing\ReceivableService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Presupuestos con ítems: el total lo calcula el servidor; estados (borrador,
 * enviado, aprobado, rechazado, anulado y vencido) y datos históricos.
 */
class QuoteItemsTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    private array $a;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->travelTo(Carbon::parse('2026-10-15 10:00'));
        $this->a = $this->makeTenant();
        $this->profesional($this->a);
        $this->actingInPanel($this->a['admin']);
    }

    private function profesional(array $tenant): void
    {
        Subscription::create(['company_id' => $tenant['company']->id, 'provider' => 'mercadopago', 'plan' => 'profesional', 'status' => 'authorized', 'amount' => 119000, 'current_period_end' => now()->addMonth()]);
    }

    private function header(array $overrides = []): array
    {
        return [
            'client_id' => $this->a['building']->client_id,
            'building_id' => $this->a['building']->id,
            'unit' => 'Ascensor 1',
            'title' => 'Cambio de contactor y cable',
            'issued_at' => '2026-10-15',
            'valid_until' => '2026-10-30',
            'status' => Quote::DRAFT,
            'priority' => 'normal',
            ...$overrides,
        ];
    }

    private function items(): array
    {
        return [
            ['concept' => 'Cambio de contactor', 'description' => 'Schneider LC1D', 'quantity' => 1, 'unit_price' => 100000],
            ['concept' => 'Cambio de cable', 'description' => null, 'quantity' => 10, 'unit_price' => 5000],
        ];
    }

    private function create(array $items, array $header = [])
    {
        return Livewire::test(CreateQuote::class)
            ->fillForm([...$this->header($header), 'items' => $items])
            ->call('create');
    }

    public function test_quote_with_several_items_and_the_total_is_calculated_on_the_server(): void
    {
        $this->create($this->items())->assertHasNoFormErrors();

        $quote = Quote::sole();
        $this->assertEquals(150000, $quote->amount);
        $this->assertSame(['Cambio de contactor', 'Cambio de cable'], $quote->items->pluck('concept')->all());
        $this->assertEquals([100000, 50000], $quote->items->pluck('subtotal')->map(fn ($v) => (float) $v)->all());
        $this->assertSame([1, 2], $quote->items->pluck('position')->all()); // orden de carga
        $this->assertSame($this->a['company']->id, $quote->items->first()->company_id);
        $this->assertSame(Quote::DRAFT, $quote->status);
        $this->assertSame('2026-10-30', $quote->valid_until->toDateString());
    }

    public function test_decimals_and_quantities(): void
    {
        $this->create([
            ['concept' => 'Cable', 'quantity' => '2.5', 'unit_price' => '1234.56'],      // 3086.40
            ['concept' => 'Mano de obra', 'quantity' => 3, 'unit_price' => '0.33'],    // 0.99
            ['concept' => 'Bonificado', 'quantity' => 1, 'unit_price' => 0],           // 0
        ])->assertHasNoFormErrors();

        $this->assertSame('3087.39', Quote::sole()->amount);
    }

    public function test_the_total_sent_by_the_browser_is_never_trusted(): void
    {
        Livewire::test(CreateQuote::class)
            ->fillForm([...$this->header(), 'items' => $this->items()])
            ->set('data.amount', 1)
            ->set('data.company_id', 999)
            ->call('create')
            ->assertHasNoFormErrors();

        $quote = Quote::sole();
        $this->assertEquals(150000, $quote->amount);
        $this->assertSame($this->a['company']->id, $quote->company_id);

        // Ni un subtotal inventado en un ítem, por ningún camino.
        $item = $quote->items->first();
        $item->forceFill(['subtotal' => 1, 'unit_price' => 100000, 'quantity' => 2])->save();
        $this->assertEquals(200000, $item->fresh()->subtotal);
        $this->assertEquals(250000, $quote->fresh()->amount);
    }

    public function test_invalid_items_and_quotes_without_items_are_rejected(): void
    {
        $this->create([])->assertHasFormErrors(['items']);
        $this->create([['concept' => '', 'quantity' => 1, 'unit_price' => 10]])->assertHasFormErrors();
        $this->create([['concept' => 'X', 'quantity' => 0, 'unit_price' => 10]])->assertHasFormErrors();
        $this->create([['concept' => 'X', 'quantity' => 1, 'unit_price' => -5]])->assertHasFormErrors();
        $this->create([['concept' => 'X', 'quantity' => 1, 'unit_price' => 10]], ['valid_until' => '2026-10-01'])->assertHasFormErrors(['valid_until']);

        $this->assertSame(0, Quote::count());
        $this->assertSame(0, QuoteItem::count());
    }

    public function test_editing_items_recalculates_the_total(): void
    {
        $this->create($this->items());
        $quote = Quote::sole();

        Livewire::test(EditQuote::class, ['record' => $quote->getRouteKey()])
            ->fillForm(['items' => [['concept' => 'Cambio de contactor', 'quantity' => 2, 'unit_price' => 100000]]])
            ->call('save')
            ->assertHasNoFormErrors();

        $quote->refresh();
        $this->assertSame(1, $quote->items()->count());
        $this->assertEquals(200000, $quote->amount);
    }

    public function test_approval_feeds_the_receivable_and_then_the_quote_is_locked(): void
    {
        $this->create($this->items(), ['status' => Quote::APPROVED]);
        $quote = Quote::sole();

        Livewire::test(ListQuotes::class)
            ->callTableAction('generateReceivable', $quote, data: ['concept' => 'Presupuesto', 'amount' => $quote->amount, 'due_date' => '2026-10-30'])
            ->assertHasNoTableActionErrors();

        $this->assertEquals(150000, Receivable::sole()->amount);

        // Con un cobro activo: no se edita ni se borra (el importe ya se cobra).
        $this->assertFalse(QuoteResource::canEdit($quote->fresh()));
        $this->get(QuoteResource::getUrl('edit', ['record' => $quote]))->assertForbidden();
        Livewire::test(ViewQuote::class, ['record' => $quote->getRouteKey()])->assertActionHidden('edit');

        // Aprobado sigue cerrado aunque se anule el cobro (trazabilidad): para
        // cambiarlo se duplica.
        app(ReceivableService::class)->void(Receivable::sole(), 'Se corrige el presupuesto');
        $this->assertFalse(QuoteResource::canEdit($quote->fresh()));
    }

    public function test_rejection_void_and_expiration(): void
    {
        $this->create($this->items(), ['status' => Quote::SENT]);
        $sent = Quote::sole();
        $this->create($this->items(), ['status' => Quote::APPROVED]);
        $approved = Quote::latest('id')->first();
        $this->create($this->items(), ['status' => Quote::REJECTED]);
        $this->create($this->items(), ['status' => Quote::VOID]);
        $void = Quote::latest('id')->first();

        $this->assertNotNull($void->voided_at);
        $this->assertSame('Anulado', $void->displayStatusLabel());
        $this->assertSame('Enviado', $sent->displayStatusLabel());

        // Pasa la validez: el enviado vence; el aprobado no.
        $this->travelTo(Carbon::parse('2026-11-05'));
        $this->assertSame(Quote::EXPIRED, $sent->fresh()->displayStatus());
        $this->assertSame('Vencido', $sent->fresh()->displayStatusLabel());
        $this->assertSame(Quote::APPROVED, $approved->fresh()->displayStatus());

        Livewire::test(ListQuotes::class)
            ->filterTable('status', Quote::EXPIRED)->assertCountTableRecords(1)
            ->filterTable('status', Quote::SENT)->assertCountTableRecords(0)
            ->filterTable('status', Quote::REJECTED)->assertCountTableRecords(1);

        // Un vencido no se puede convertir en cobro (no está aprobado).
        $this->expectException(ValidationException::class);
        app(ReceivableService::class)->fromQuote($sent->fresh(), 'X', 1, today(), $this->a['admin']);
    }

    public function test_dashboard_counts_open_quotes_only(): void
    {
        $this->create($this->items(), ['status' => Quote::DRAFT]);
        $this->create($this->items(), ['status' => Quote::SENT]);
        $this->create($this->items(), ['status' => Quote::SENT, 'issued_at' => '2026-09-01', 'valid_until' => '2026-09-30']); // vencido
        $this->create($this->items(), ['status' => Quote::APPROVED]);

        $stats = collect((fn () => $this->getStats())->call(new AdminStats))
            ->mapWithKeys(fn ($stat) => [(string) $stat->getLabel() => (string) $stat->getValue()]);

        $this->assertSame('2', $stats['Presupuestos pendientes']);
    }

    public function test_ids_of_another_company_are_rejected(): void
    {
        auth()->logout();
        $b = $this->makeTenant();
        $this->actingInPanel($this->a['admin']);

        $this->create($this->items(), ['client_id' => $b['building']->client_id])->assertHasFormErrors(['client_id']);
        $this->create($this->items(), ['building_id' => $b['building']->id])->assertHasFormErrors(['building_id']);
        $this->assertSame(0, Quote::withoutGlobalScopes()->count());

        // Un ítem siempre es de la empresa de su presupuesto.
        $this->create($this->items());
        $quote = Quote::sole();
        $item = new QuoteItem(['quote_id' => $quote->id, 'concept' => 'Inyectado', 'quantity' => 1, 'unit_price' => 1]);
        $item->company_id = $b['company']->id;
        $item->save();
        $this->assertSame($this->a['company']->id, $item->fresh()->company_id);

        // Otra empresa no lo ve ni por el panel ni por su link público.
        auth()->logout();
        $this->profesional($b);
        $this->actingInPanel($b['admin'])->get(QuoteResource::getUrl('view', ['record' => $quote]))->assertNotFound();
        // Enlaces sin firma (los permanentes de antes) ya no abren; con otra empresa en la URL, tampoco.
        $this->get("/{$this->a['company']->slug}/quote/{$quote->public_token}")->assertStatus(410);
        $quote->update(['status' => Quote::SENT]);
        $signed = $quote->fresh()->signedPublicUrl();
        $this->get(str_replace("/{$this->a['company']->slug}/", "/{$b['company']->slug}/", $signed))->assertStatus(410);
        $this->get($signed)->assertOk()
            ->assertSee('Cambio de contactor')->assertSee('$ 150.001,00')->assertSee('30/10/2026'); // + el ítem de $1 de arriba
    }

    public function test_public_links_open_complete_even_with_another_company_logged_in(): void
    {
        $this->create($this->items());
        $quote = Quote::sole();
        $note = DeliveryNote::factory()->create(['building_id' => $this->a['building']->id]);
        auth()->logout();
        $b = $this->makeTenant();

        // En el navegador del cliente quedó logueada otra cuenta (de otra empresa).
        $this->actingAs($b['admin']);

        $quote->update(['status' => Quote::SENT]);
        $this->get($quote->fresh()->signedPublicUrl())->assertOk()
            ->assertSee('Cambio de contactor')
            ->assertSee(e($this->a['building']->name), false)
            ->assertDontSee(e($b['building']->name), false);

        $this->get("/{$this->a['company']->slug}/public/delivery-notes/{$note->public_token}")->assertOk()
            ->assertSee(e($this->a['building']->name), false);

        // Pero el token de A con el slug de B, nunca.
        $this->get("/{$b['company']->slug}/quote/{$quote->public_token}")->assertStatus(410); // aviso genérico, sin datos
        $this->get("/{$b['company']->slug}/public/delivery-notes/{$note->public_token}")->assertNotFound();
    }

    public function test_historical_quotes_are_migrated_with_one_item_and_the_same_total(): void
    {
        // Presupuesto de antes: solo título e importe, estado "pending".
        $legacy = Quote::factory()->create(['building_id' => $this->a['building']->id, 'title' => 'Reparación de puerta', 'amount' => 87500.50]);
        DB::table('quotes')->where('id', $legacy->id)->update(['status' => 'pending']);
        DB::table('quote_items')->where('quote_id', $legacy->id)->delete();

        $migration = require database_path('migrations/2026_10_16_100400_add_items_and_terms_to_quotes.php');
        $migration->down();
        $this->assertSame('pending', DB::table('quotes')->where('id', $legacy->id)->value('status'));
        $migration->up();

        $legacy = Quote::find($legacy->id);
        $this->assertSame(Quote::DRAFT, $legacy->status);
        $this->assertEquals(87500.50, $legacy->amount);
        $this->assertSame($legacy->created_at->toDateString(), $legacy->issued_at->toDateString());

        $item = $legacy->items()->sole();
        $this->assertSame('Reparación de puerta', $item->concept);
        $this->assertEquals(1, $item->quantity);
        $this->assertEquals(87500.50, $item->subtotal);
        $this->assertSame($legacy->company_id, $item->company_id);

        // Y se abre y se edita normalmente en el panel.
        Livewire::test(EditQuote::class, ['record' => $legacy->getRouteKey()])->call('save')->assertHasNoFormErrors();
        $this->assertEquals(87500.50, $legacy->fresh()->amount);
    }
}
