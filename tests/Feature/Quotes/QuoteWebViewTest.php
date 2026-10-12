<?php

namespace Tests\Feature\Quotes;

use App\Filament\Resources\Quotes\QuoteResource;
use App\Models\Quote;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Presupuesto en pantalla (enlace, portal y admin) separado de la plantilla
 * del PDF: responsive, sin tablas anidadas ni anchos fijos, y con exactamente
 * los mismos importes, conceptos y condiciones que el PDF.
 */
class QuoteWebViewTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    private array $a;

    private Quote $quote;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->a = $this->makeTenant();
        $this->a['company']->forceFill(['bank_cbu' => '0000003100000000000001', 'bank_alias' => 'norte.pagos'])->save();

        auth()->logout();
        $this->quote = Quote::factory()->create([
            'company_id' => $this->a['company']->id, 'building_id' => $this->a['building']->id, 'status' => Quote::SENT, 'issued_at' => now(),
            'title' => 'Cambio de cables de tracción', 'description' => 'Reemplazo de los 4 cables.', 'conditions' => "50% de anticipo.\nSaldo contra entrega.",
            'notes' => 'NOTA INTERNA: margen 30%', 'valid_until' => now()->addDays(15), 'unit' => 'Ascensor 1',
        ]);
        foreach ([['Cable de acero Ø10', 4, 125000.5, 'Por metro lineal'], ['Mano de obra', 1, 380000, null]] as $i => [$concept, $qty, $price, $desc]) {
            $this->quote->items()->create(['position' => $i + 1, 'concept' => $concept, 'quantity' => $qty, 'unit_price' => $price, 'description' => $desc]);
        }
        $this->quote->refresh();
        $this->quote->forceFill(['shared_with_client' => true])->save();
    }

    /** Importes "$ 1.234,56", conceptos y condiciones que aparecen en un HTML. */
    private function figures(string $html): array
    {
        preg_match_all('/\$ [0-9.]+,[0-9]{2}/u', $html, $money);

        return [
            'money' => collect($money[0])->unique()->sort()->values()->all(),
            'concepts' => array_values(array_filter(['Cable de acero Ø10', 'Mano de obra', 'Por metro lineal', 'Saldo contra entrega.', 'Alias norte.pagos'], fn ($t) => str_contains($html, $t))),
        ];
    }

    private function webBlock(string $html): string
    {
        $start = strpos($html, '<div class="qw">');
        $this->assertNotFalse($start, 'usa la vista web responsive');

        return substr($html, $start);
    }

    public function test_the_client_link_uses_the_responsive_view_with_the_same_figures_as_the_pdf(): void
    {
        $html = $this->get($this->quote->signedPublicUrl())->assertOk()
            ->assertSee('width=device-width', false)
            ->getContent();
        $pdf = view('quotes.pdf', ['quote' => $this->quote->load(['items', 'building.client', 'client', 'company'])])->render();

        $this->assertSame($this->figures($pdf), $this->figures($this->webBlock($html)));
        $this->assertContains('$ 880.002,00', $this->figures($html)['money']); // total
        $this->assertContains('$ 500.002,00', $this->figures($html)['money']); // 4 × 125.000,50
        $this->assertStringNotContainsString('NOTA INTERNA', $html);
    }

    public function test_the_web_view_has_no_nested_tables_or_fixed_widths(): void
    {
        $block = $this->webBlock($this->get($this->quote->signedPublicUrl())->getContent());
        $block = substr($block, 0, strpos($block, 'Emitido con Ascento'));

        $this->assertSame(1, substr_count($block, '<table'), 'una sola tabla (los ítems), sin anidar');
        $this->assertDoesNotMatchRegularExpression('/width:\s*[4-9][0-9]{2,}px/', $block, 'sin anchos fijos grandes');
        // En el celular cada ítem muestra cantidad × precio y subtotal (bloque .qw-xs).
        $this->assertStringContainsString('4 × $ 125.000,50', $block);
    }

    public function test_the_portal_and_the_admin_preview_use_the_web_view(): void
    {
        $client = User::factory()->create(['email' => 'cliente@consorcio.test']);
        $client->forceFill(['role' => User::ROLE_CLIENT, 'company_id' => $this->a['company']->id, 'client_id' => $this->a['building']->client_id])->save();
        $client->portalBuildings()->sync([$this->a['building']->id]);

        $portal = $this->actingAs($client)->get(route('portal.quote', $this->quote))->assertOk()->getContent();
        $this->assertStringContainsString('$ 880.002,00', $this->webBlock($portal));
        $this->assertStringNotContainsString('NOTA INTERNA', $portal);

        $this->actingInPanel($this->a['admin']);
        $admin = $this->get(QuoteResource::getUrl('view', ['record' => $this->quote]))->assertOk()->getContent();
        $this->assertStringContainsString('$ 880.002,00', $this->webBlock($admin));
    }

    public function test_the_pdf_keeps_its_print_template(): void
    {
        $html = view('quotes.pdf', ['quote' => $this->quote->load(['items', 'building.client', 'client', 'company'])])->render();

        $this->assertStringContainsString('DejaVu Sans', $html);
        $this->assertStringNotContainsString('class="qw"', $html);
        $this->assertStringContainsString('$ 880.002,00', $html);
        $this->assertStringNotContainsString('NOTA INTERNA', $html);

        $this->actingAs($this->a['admin']);
        $res = $this->get(route('quotes.pdf', $this->quote))->assertOk();
        $this->assertStringStartsWith('%PDF', $res->getContent());
    }
}
