<?php

namespace Tests\Feature\Marketplace;

use App\Filament\Pages\Marketplace\PartsCatalog;
use App\Filament\Pages\Marketplace\PartsRequests;
use App\Filament\Resources\Marketplace\Pages\ListSuppliers;
use App\Models\Marketplace\MarketplaceRequest;
use App\Models\Marketplace\MarketplaceSettings;
use App\Models\Marketplace\Supplier;
use App\Models\Marketplace\SupplierProduct;
use App\Models\Marketplace\SupplierSubscription;
use App\Models\User;
use App\Services\Marketplace\MarketplaceRequests;
use App\Support\Marketplace\SupplierStanding;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithTenants;
use Tests\Feature\Marketplace\Concerns\MakesMarketplace;
use Tests\TestCase;

/**
 * Modelo comercial de los proveedores: prueba (una sola vez), cobros
 * habilitados o no, modalidad gratuita internacional, vencimientos sin
 * borrar nada y la moderación independiente del pago.
 */
class SupplierCommercialTest extends TestCase
{
    use InteractsWithTenants, MakesMarketplace, RefreshDatabase;

    private function charge(float $price = 25000): MarketplaceSettings
    {
        config(['services.mercadopago.access_token' => 'APP_USR-TEST']);

        return MarketplaceSettings::create(['trial_days' => 30, 'billing_enabled' => true, 'plan_price' => $price, 'plan_currency' => 'ARS', 'international_free' => true]);
    }

    private function trialEnded(Supplier $supplier): Supplier
    {
        $supplier->forceFill(['trial_started_at' => now()->subDays(40), 'trial_ends_at' => now()->subDays(10)])->save();

        return $supplier->fresh();
    }

    public function test_the_trial_starts_once_on_approval_and_never_restarts(): void
    {
        MarketplaceSettings::create(['trial_days' => 45]);
        [$supplier] = $this->makeSupplier(Supplier::PENDING);
        $this->assertNull($supplier->trial_ends_at);
        $this->actingInPanel(User::factory()->superAdmin()->create());

        Livewire::test(ListSuppliers::class)->callTableAction('approve', $supplier);
        $first = $supplier->fresh()->trial_ends_at;
        $this->assertTrue($first->between(now()->addDays(44), now()->addDays(46)));

        // Suspender y reactivar (o rechazar y volver a aprobar) no reinicia la prueba.
        $this->travel(20)->days();
        Livewire::test(ListSuppliers::class)->callTableAction('suspend', $supplier->fresh(), ['reason' => 'Revisión']);
        Livewire::test(ListSuppliers::class)->callTableAction('reactivate', $supplier->fresh());
        $this->assertTrue($supplier->fresh()->trial_ends_at->equalTo($first));
        $supplier->fresh()->startTrialOnce(90);
        $this->assertTrue($supplier->fresh()->trial_ends_at->equalTo($first));
    }

    public function test_when_the_trial_ends_with_billing_on_the_catalog_hides_without_deleting_anything(): void
    {
        [$supplier, $supplierUser] = $this->makeSupplier();
        $product = $this->makeProduct($supplier, ['name' => 'Polea tractora']);
        $a = $this->makeTenant();
        $old = app(MarketplaceRequests::class)->create($a['admin'], $supplier->id, [$product->id => 1], ['contact_name' => 'Ana', 'contact_email' => 'ana@e.test'], 'k');

        // Cobros apagados: al terminar la prueba sigue sin cargo (nadie queda bloqueado sin poder pagar).
        $supplier = $this->trialEnded($supplier);
        $this->assertSame(SupplierStanding::FREE_UNBILLED, $supplier->standing());
        $this->assertTrue($product->fresh()->isVisible());

        // Cobros encendidos: prueba vencida, catálogo oculto en el servidor (consultas y acciones).
        $this->charge();
        $this->assertSame(SupplierStanding::TRIAL_EXPIRED, $supplier->fresh()->standing());
        $this->assertFalse($product->fresh()->isVisible());
        $this->assertSame(0, SupplierProduct::visible()->count());
        $this->actingInPanel($a['admin']);
        Livewire::test(PartsCatalog::class)->assertDontSee('Polea tractora');
        Livewire::test(PartsCatalog::class)->call('addToRequest', $product->id)->assertNotFound();
        try {
            app(MarketplaceRequests::class)->create($a['admin'], $supplier->id, [$product->id => 1], ['contact_name' => 'Ana', 'contact_email' => 'ana@e.test'], 'k2');
            $this->fail('Recibió un pedido nuevo con la prueba vencida');
        } catch (ValidationException) {
        }

        // Nada se borró: producto, estado, fotos y pedidos siguen.
        $this->assertSame(SupplierProduct::PUBLISHED, $product->fresh()->status);
        $this->assertTrue($old->fresh()->exists);

        // El proveedor entra, ve su estado y lo que tiene; no publica ni cotiza.
        auth()->logout();
        $this->actingAs($supplierUser)->get(route('suppliers.billing'))->assertOk()->assertSee('Prueba vencida')->assertSee('Suscribirme con Mercado Pago');
        $this->get(route('suppliers.products.edit', $product->id))->assertOk();
        $this->post(route('suppliers.products.store'), ['name' => 'Nuevo', 'category' => 'doors', 'price_mode' => 'quote', 'availability' => 'in_stock'])->assertSessionHasErrors('estado');
        $this->assertSame(1, SupplierProduct::count());
        $this->post(route('suppliers.requests.quote', $old->reference), ['precios' => [$old->items()->value('id') => 10]])->assertSessionHasErrors('estado');
        $this->post(route('suppliers.requests.decline', $old->reference), ['nota' => 'Sin stock'])->assertSessionHasNoErrors();

        // Con una suscripción paga vuelve a verse lo que estaba publicado, sin tocar nada.
        SupplierSubscription::create(['supplier_id' => $supplier->id, 'external_reference' => 'ascento-supplier-'.$supplier->id, 'status' => SupplierSubscription::AUTHORIZED,
            'amount' => 25000, 'currency' => 'ARS', 'current_period_start' => now(), 'current_period_end' => now()->addMonth()]);
        $this->assertSame(SupplierStanding::ACTIVE, $supplier->fresh()->standing());
        $this->assertTrue($product->fresh()->isVisible());
    }

    public function test_moderation_wins_over_payment_and_hidden_products_stay_hidden(): void
    {
        $this->charge();
        [$supplier, $supplierUser] = $this->makeSupplier(Supplier::SUSPENDED);
        $hidden = $this->makeProduct($supplier, ['name' => 'Oculto']);
        $hidden->forceFill(['hidden_by_admin' => true, 'hidden_reason' => 'Fotos ajenas'])->save();
        SupplierSubscription::create(['supplier_id' => $supplier->id, 'external_reference' => 'ascento-supplier-'.$supplier->id, 'status' => SupplierSubscription::AUTHORIZED,
            'amount' => 25000, 'currency' => 'ARS', 'current_period_end' => now()->addMonth()]);

        // Pagó, pero está suspendido: no opera, no puede suscribirse ni publicar.
        $this->assertSame(SupplierStanding::SUSPENDED, $supplier->fresh()->standing());
        $this->actingAs($supplierUser)->post(route('suppliers.billing.subscribe'))->assertSessionHasErrors('suscripcion');
        $this->get(route('suppliers.billing'))->assertSee('pagar no la reactiva', false);

        // Reactivado: lo publicado vuelve; lo oculto por moderación, no (ni el proveedor lo puede publicar).
        $supplier->forceFill(['status' => Supplier::APPROVED])->save();
        $this->assertFalse($hidden->fresh()->isVisible());
        $hidden->forceFill(['status' => SupplierProduct::PAUSED])->save();
        $this->actingAs($supplierUser->fresh())->post(route('suppliers.products.status', $hidden->id), ['estado' => 'published'])->assertSessionHasErrors('estado');
        $this->assertSame(SupplierProduct::PAUSED, $hidden->fresh()->status);
    }

    public function test_suppliers_from_other_countries_operate_free_or_not_but_never_pay(): void
    {
        $this->charge();
        [$supplier, $supplierUser] = $this->makeSupplier();
        $supplier->forceFill(['country' => 'CL', 'currency' => 'CLP'])->save();
        $product = $this->makeProduct($this->trialEnded($supplier), ['name' => 'Rodamiento']);

        $this->assertSame(SupplierStanding::FREE_INTERNATIONAL, $supplier->fresh()->standing());
        $this->assertTrue($product->fresh()->isVisible());
        $this->actingAs($supplierUser)->get(route('suppliers.billing'))->assertOk()->assertSee('sin cargo')->assertDontSee('Suscribirme con Mercado Pago');
        $this->post(route('suppliers.billing.subscribe'))->assertSessionHasErrors('suscripcion');
        $this->get(route('suppliers.featured'))->assertSee('solo están disponibles para proveedores registrados en Argentina');

        // El país no se cambia desde el perfil (aunque se mande en el formulario).
        $this->put(route('suppliers.profile.update'), ['name' => 'Otro', 'contact_email' => 'x@y.test', 'contact_phone' => '+56 9 1234 5678', 'coverage' => 'Santiago', 'categories' => ['doors'], 'country' => 'AR', 'currency' => 'ARS']);
        $this->assertSame(['CL', 'CLP'], [$supplier->fresh()->country, $supplier->fresh()->currency]);

        // Modalidad gratuita apagada: deja de verse (sin poder pagar).
        MarketplaceSettings::current()->update(['international_free' => false]);
        $this->assertSame(SupplierStanding::UNAVAILABLE_COUNTRY, $supplier->fresh()->standing());
        $this->assertFalse($product->fresh()->isVisible());
    }

    public function test_accepted_operations_continue_when_the_supplier_stops_operating(): void
    {
        [$supplier, $supplierUser] = $this->makeSupplier();
        $product = $this->makeProduct($supplier);
        $a = $this->makeTenant();
        $service = app(MarketplaceRequests::class);
        $request = $service->create($a['admin'], $supplier->id, [$product->id => 1], ['contact_name' => 'Ana', 'contact_email' => 'ana@e.test'], 'k');
        $service->quote($supplierUser, $request->fresh(), [$request->items()->value('id') => 10], 7, null);
        $other = $service->create($a['admin'], $supplier->id, [$product->id => 2], ['contact_name' => 'Ana', 'contact_email' => 'ana@e.test'], 'k2');
        $service->quote($supplierUser, $other->fresh(), [$other->items()->value('id') => 10], 7, null);
        $service->buyerMove($a['admin'], $request->fresh(), MarketplaceRequest::ACCEPTED, null, $request->fresh()->quoteVersion());

        $this->trialEnded($supplier);
        $this->charge();

        // Lo aceptado sigue: se completa y las partes pueden comentar. Nada se canceló solo.
        $service->comment($supplierUser, $request->fresh(), 'Sale mañana por transporte');
        $service->buyerMove($a['admin'], $request->fresh(), MarketplaceRequest::COMPLETED);
        $this->assertSame(MarketplaceRequest::COMPLETED, $request->fresh()->status);
        // Lo cotizado y no aceptado no se puede aceptar mientras no opere (se puede cancelar).
        $this->expectException(ValidationException::class);
        try {
            $service->buyerMove($a['admin'], $other->fresh(), MarketplaceRequest::ACCEPTED, null, $other->fresh()->quoteVersion());
        } finally {
            $this->assertSame(MarketplaceRequest::QUOTED, $other->fresh()->status);
            $this->actingInPanel($a['admin']);
            Livewire::test(PartsRequests::class, ['pedido' => $other->id])->assertSee('no está disponible en Ascento por ahora');
        }
    }
}
