<?php

namespace Tests\Feature\Marketplace;

use App\Filament\Pages\Marketplace\PartsCatalog;
use App\Filament\Pages\Marketplace\PartsRequests;
use App\Filament\Pages\MarketplaceSettingsPage;
use App\Filament\Resources\Marketplace\FeaturedPlacementResource;
use App\Filament\Resources\Marketplace\MarketplaceRequestResource;
use App\Filament\Resources\Marketplace\Pages\ViewMarketplaceRequest;
use App\Filament\Resources\Marketplace\SupplierPaymentResource;
use App\Models\Company;
use App\Models\Marketplace\FeaturedPlacement;
use App\Models\Marketplace\MarketplaceRequest;
use App\Models\Marketplace\MarketplaceSettings;
use App\Models\Marketplace\Supplier;
use App\Models\Marketplace\SupplierPayment;
use App\Models\Marketplace\SupplierProduct;
use App\Models\Marketplace\SupplierSubscription;
use App\Models\PlatformAuditLog;
use App\Models\User;
use App\Services\Companies\CompanyEraser;
use App\Services\Marketplace\MarketplaceRequests;
use App\Services\Marketplace\SupplierEraser;
use App\Support\Marketplace\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Concerns\InteractsWithTenants;
use Tests\Feature\Marketplace\Concerns\MakesMarketplace;
use Tests\TestCase;

/**
 * Operación del marketplace: conversación e incidencias entre las partes,
 * intervención auditada del SuperAdmin, orden del catálogo (pagar no gana a
 * la relevancia), monedas explícitas y eliminación de datos sin romper el
 * historial de la otra parte.
 */
class MarketplaceOperationsTest extends TestCase
{
    use InteractsWithTenants, MakesMarketplace, RefreshDatabase;

    public function test_parties_talk_report_incidents_and_the_super_admin_intervenes_without_rewriting_history(): void
    {
        [$supplier, $supplierUser] = $this->makeSupplier();
        $product = $this->makeProduct($supplier);
        $a = $this->makeTenant();
        $b = $this->makeTenant();
        $super = User::factory()->superAdmin()->create();
        $service = app(MarketplaceRequests::class);
        $request = $service->create($a['admin'], $supplier->id, [$product->id => 2], ['contact_name' => 'Ana', 'contact_email' => 'ana@e.test', 'contact_phone' => '+54 9 11 4444-5555'], 'k');
        $service->quote($supplierUser, $request->fresh(), [$request->items()->value('id') => 100], 7, null);
        $service->buyerMove($a['admin'], $request->fresh(), MarketplaceRequest::ACCEPTED, null, $request->fresh()->quoteVersion());

        // Comentarios: cada uno le avisa a la otra parte; alguien ajeno no puede.
        $this->actingInPanel($a['admin']);
        Livewire::test(PartsRequests::class, ['pedido' => $request->id])->set('comment', '¿Pueden entregar el lunes?')->call('sendComment')->assertHasNoErrors();
        $this->assertSame(1, $supplierUser->notifications()->where('data->title', 'Comentario en el pedido '.$request->reference)->count());
        auth()->logout();
        $this->actingAs($supplierUser)->post(route('suppliers.requests.comment', $request->reference), ['comentario' => 'Sí, por la mañana'])->assertSessionHasNoErrors();
        $this->get(route('suppliers.requests.show', $request->reference))->assertSee('¿Pueden entregar el lunes?')->assertSee('wa.me/5491144445555', false);
        $this->assertSame(1, $a['admin']->notifications()->where('data->title', 'Comentario en el pedido '.$request->reference)->count());
        try {
            $service->comment($b['admin'], $request->fresh(), 'Hola');
            $this->fail('Otra empresa comentó');
        } catch (HttpException $e) {
            $this->assertSame(404, $e->getStatusCode());
        }

        // Incidencia: una abierta a la vez; avisa al equipo.
        $this->post(route('suppliers.requests.incident', $request->reference), ['incidencia' => 'No responden desde hace una semana'])->assertSessionHasNoErrors();
        $this->post(route('suppliers.requests.incident', $request->reference), ['incidencia' => 'Otra vez'])->assertSessionHasErrors('incidencia');
        $this->assertSame(1, $super->notifications()->where('data->title', 'Incidencia en un pedido de repuestos')->count());

        // El SuperAdmin ve el pedido global, resuelve y anula: auditado, sin tocar importes.
        auth()->logout();
        $this->actingInPanel($super);
        $this->get(MarketplaceRequestResource::getUrl('view', ['record' => $request]))->assertOk()->assertSee('No responden desde hace una semana')->assertSee('ARS 200');
        Livewire::test(ViewMarketplaceRequest::class, ['record' => $request->id])->callAction('resolveIncident', ['note' => 'Hablamos con las dos partes']);
        Livewire::test(ViewMarketplaceRequest::class, ['record' => $request->id])->callAction('adminCancel', ['reason' => 'Las partes acordaron anularlo']);
        $request->refresh();
        $this->assertSame([MarketplaceRequest::CANCELLED, '200.00', false], [$request->status, $request->quoted_total, $request->hasOpenIncident()]);
        $this->assertSame(['marketplace.request.incident_resolved', 'marketplace.request.cancelled_by_admin'], PlatformAuditLog::orderBy('id')->pluck('action')->all());
        $this->assertSame(['status', 'status', 'status', 'note', 'note', 'incident', 'resolved', 'status'], $request->events()->pluck('kind')->all());
        $this->assertSame(1, $supplierUser->notifications()->where('data->title', 'Ascento anuló el pedido '.$request->reference)->count());

        // Solo el SuperAdmin entra a las pantallas globales.
        auth()->logout();
        $this->actingInPanel($a['admin']);
        foreach ([MarketplaceRequestResource::getUrl(), SupplierPaymentResource::getUrl(), FeaturedPlacementResource::getUrl(), MarketplaceSettingsPage::getUrl()] as $url) {
            $this->assertNotSame(200, $this->get($url)->status(), $url);
        }
    }

    public function test_the_catalog_ranks_relevance_before_paid_visibility(): void
    {
        [$supplier] = $this->makeSupplier();
        $a = $this->makeTenant();
        $relevant = $this->makeProduct($supplier, ['name' => 'Operador de puerta Fermator', 'availability' => 'in_stock', 'compatibility' => null]);
        $paidIrrelevant = $this->makeProduct($supplier, ['name' => 'Aceite hidráulico', 'description' => 'Sirve también para operador de puerta', 'compatibility' => null]);
        $paidEqual = $this->makeProduct($supplier, ['name' => 'Operador de puerta Selcom', 'availability' => 'in_stock', 'compatibility' => null]);
        foreach ([$paidIrrelevant, $paidEqual] as $p) {
            FeaturedPlacement::create(['supplier_id' => $supplier->id, 'supplier_product_id' => $p->id, 'product_name' => $p->name, 'source' => 'purchase', 'days' => 7,
                'amount' => 5000, 'currency' => 'ARS', 'status' => FeaturedPlacement::ACTIVE, 'starts_at' => now()->subDay(), 'ends_at' => now()->addDays(6)]);
        }
        $this->actingInPanel($a['admin']);

        $order = fn (array $params = []) => Livewire::test(PartsCatalog::class, $params)->viewData('products')->pluck('id')->all();
        // «operador puerta»: los dos que lo tienen en el nombre van primero; entre ellos, el destacado. El destacado irrelevante, al final.
        $this->assertSame([$paidEqual->id, $relevant->id, $paidIrrelevant->id], $order(['q' => 'operador puerta']));
        // El navegador no puede pedir otro orden.
        $this->assertSame([$paidEqual->id, $relevant->id, $paidIrrelevant->id], $order(['q' => 'operador puerta', 'orden' => 'precio', 'sort' => 'id']));
        Livewire::test(PartsCatalog::class, ['q' => 'operador puerta'])->assertSeeHtml('mk-pill featured');

        // Vencido el destacado, deja de contar.
        $this->travel(7)->days();
        $this->assertFalse($paidEqual->fresh()->isFeatured());
        $after = $order(['q' => 'operador puerta']);
        $this->assertSame($paidIrrelevant->id, end($after));
        Livewire::test(PartsCatalog::class, ['q' => 'operador puerta'])->assertDontSeeHtml('mk-pill featured');
    }

    public function test_prices_keep_their_currency_and_the_form_can_not_change_it(): void
    {
        [$supplier, $user] = $this->makeSupplier();
        $supplier->forceFill(['country' => 'CL', 'currency' => 'CLP'])->save();
        $this->actingAs($user->fresh())->post(route('suppliers.products.store'), ['name' => 'Rodamiento', 'category' => 'machines', 'price_mode' => 'fixed', 'price' => 120000, 'availability' => 'in_stock', 'currency' => 'ARS'])->assertRedirect();
        $product = SupplierProduct::sole();
        $this->assertSame('CLP', $product->currency);
        $this->assertSame('CLP 120.000', $product->priceLabel());
        $this->assertSame('ARS 1.500,50', Money::format(1500.5, 'ARS'));
        $this->assertSame('CLP', Supplier::currencyFor('CL'));
    }

    public function test_erasing_a_company_keeps_the_suppliers_history_without_its_data(): void
    {
        [$supplier, $supplierUser] = $this->makeSupplier();
        $product = $this->makeProduct($supplier);
        $a = $this->makeTenant();
        $request = app(MarketplaceRequests::class)->create($a['admin'], $supplier->id, [$product->id => 1], ['contact_name' => 'Ana Compras', 'contact_email' => 'ana@empresa.test', 'contact_phone' => '11 4444-5555', 'notes' => 'Urgente'], 'k');
        $super = User::factory()->superAdmin()->create();

        app(CompanyEraser::class)->erase($a['company'], $super, $a['company']->name);

        $this->assertNull(Company::find($a['company']->id));
        $request = MarketplaceRequest::find($request->id);
        $this->assertNotNull($request);
        $this->assertSame([null, 'Empresa dada de baja', '', null, null], [$request->company_id, $request->contact_name, $request->contact_email, $request->contact_phone, $request->notes]);
        $this->actingAs($supplierUser)->get(route('suppliers.requests.show', $request->reference))->assertOk()->assertSee('Empresa dada de baja')->assertDontSee('ana@empresa.test');
    }

    public function test_closing_a_supplier_removes_its_data_but_not_the_buyers_history_or_the_payments(): void
    {
        Storage::fake('local');
        [$supplier, $supplierUser] = $this->makeSupplier();
        $product = $this->makeProduct($supplier);
        $a = $this->makeTenant();
        $this->actingAs($supplierUser)->post(route('suppliers.products.photos.store', $product->id), ['foto' => UploadedFile::fake()->image('foto.jpg', 800, 600)]);
        $photo = $product->photos()->sole();
        auth()->logout();
        $request = app(MarketplaceRequests::class)->create($a['admin'], $supplier->id, [$product->id => 1], ['contact_name' => 'Ana', 'contact_email' => 'ana@e.test'], 'k');
        $sub = SupplierSubscription::create(['supplier_id' => $supplier->id, 'provider_subscription_id' => 'PRE1', 'external_reference' => 'ascento-supplier-'.$supplier->id, 'status' => SupplierSubscription::AUTHORIZED, 'amount' => 25000, 'currency' => 'ARS']);
        SupplierPayment::create(['supplier_id' => $supplier->id, 'kind' => 'subscription', 'supplier_subscription_id' => $sub->id, 'provider_payment_id' => 'authorized:1', 'status' => 'approved', 'amount' => 25000, 'currency' => 'ARS', 'paid_at' => now()]);
        $super = User::factory()->superAdmin()->create();
        $this->actingAs($super);

        // Con una suscripción que puede seguir cobrando, no se cierra.
        try {
            app(SupplierEraser::class)->erase($supplier, $super, $supplier->name, 'Pedido del proveedor');
            $this->fail('Cerró con una suscripción activa');
        } catch (ValidationException) {
        }
        $sub->forceFill(['status' => SupplierSubscription::CANCELED])->save();
        app(SupplierEraser::class)->erase($supplier->fresh(), $super, $supplier->name, 'Pedido del proveedor');

        $supplier->refresh();
        $this->assertNotNull($supplier->closed_at);
        $this->assertNull($supplier->contact_email);
        $this->assertNull(User::withTrashed()->find($supplierUser->id));
        $this->assertSame(0, SupplierProduct::count());
        Storage::disk('local')->assertMissing($photo->path);
        $this->assertSame(1, SupplierPayment::count()); // registro contable
        $this->assertNotNull(MarketplaceRequest::find($request->id)); // historial del comprador
        $this->assertSame('Operador de puerta', MarketplaceRequest::find($request->id)->items()->value('name'));
        $this->assertSame(1, $a['admin']->notifications()->where('data->title', 'Proveedor dado de baja')->count());
        $this->assertSame('marketplace.supplier.erased', PlatformAuditLog::latest('id')->value('action'));
    }

    public function test_settings_never_charge_without_a_price_and_are_audited(): void
    {
        $super = User::factory()->superAdmin()->create();
        $this->actingInPanel($super);
        config(['services.mercadopago.access_token' => null]);
        Livewire::test(MarketplaceSettingsPage::class)->assertSet('data.free_product_limit', 20)->assertSet('data.premium_product_limit', 1000)
            ->set('data.premium_enabled', true)->call('save')->assertNotified('Falta el precio');
        Livewire::test(MarketplaceSettingsPage::class)->set('data.premium_enabled', true)->set('data.premium_price', 25000)->call('save')->assertNotified('Mercado Pago no está configurado');
        $this->assertFalse(MarketplaceSettings::current()->sellsPremium());

        // Bajar el límite gratuito deja fuera del límite lo que sobra (sin borrar) y queda auditado.
        [$supplier] = $this->makeSupplier();
        $this->makeProduct($supplier, ['name' => 'Uno']);
        $this->makeProduct($supplier, ['name' => 'Dos']);
        config(['services.mercadopago.access_token' => 'APP_USR-TEST']);
        // Un país sin pasarela no se puede habilitar.
        Livewire::test(MarketplaceSettingsPage::class)->set('data.billing_countries', ['AR', 'CL'])->call('save')->assertHasErrors('data.billing_countries.1');
        Livewire::test(MarketplaceSettingsPage::class)->set('data.premium_enabled', true)->set('data.premium_price', 25000)->set('data.free_product_limit', 1)
            ->set('data.billing_countries', ['AR'])->call('save')->assertHasNoErrors()->assertNotified('Configuración guardada');
        $settings = MarketplaceSettings::current();
        $this->assertTrue($settings->sellsPremium());
        $this->assertSame(['AR'], $settings->billing_countries);
        $this->assertSame(1, SupplierProduct::where('status', SupplierProduct::PUBLISHED)->count());
        $this->assertSame(2, SupplierProduct::count());
        $this->assertSame('marketplace.settings.updated', PlatformAuditLog::latest('id')->value('action'));
    }
}
