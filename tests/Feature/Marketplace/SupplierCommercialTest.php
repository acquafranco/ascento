<?php

namespace Tests\Feature\Marketplace;

use App\Filament\Pages\Marketplace\PartsCatalog;
use App\Filament\Pages\MarketplaceSettingsPage;
use App\Filament\Resources\Marketplace\Pages\ListSupplierProducts;
use App\Models\Marketplace\FeaturedPlacement;
use App\Models\Marketplace\MarketplaceRequest;
use App\Models\Marketplace\MarketplaceSettings;
use App\Models\Marketplace\Supplier;
use App\Models\Marketplace\SupplierProduct;
use App\Models\Marketplace\SupplierSubscription;
use App\Models\User;
use App\Services\Marketplace\MarketplaceRequests;
use App\Services\Marketplace\PublicationLimits;
use App\Support\Marketplace\SupplierStanding;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithTenants;
use Tests\Feature\Marketplace\Concerns\MakesMarketplace;
use Tests\TestCase;

/**
 * Modelo definitivo: publicar es gratis para siempre con un límite de
 * publicaciones; Premium (pago) lo amplía. Nada se borra: lo que no entra
 * queda «fuera del límite», distinto de lo oculto por moderación.
 */
class SupplierCommercialTest extends TestCase
{
    use InteractsWithTenants, MakesMarketplace, RefreshDatabase;

    private function limits(int $free = 20, int $premium = 1000): void
    {
        MarketplaceSettings::current()->update(['free_product_limit' => $free, 'premium_product_limit' => $premium]);
    }

    private function premium(Supplier $supplier, string $status = SupplierSubscription::AUTHORIZED): SupplierSubscription
    {
        return SupplierSubscription::updateOrCreate(['supplier_id' => $supplier->id], ['external_reference' => 'ascento-supplier-'.$supplier->id, 'provider_subscription_id' => 'PRE'.$supplier->id,
            'status' => $status, 'amount' => 25000, 'currency' => 'ARS', 'current_period_start' => now(), 'current_period_end' => now()->addMonth()]);
    }

    /** @return list<SupplierProduct> */
    private function drafts(Supplier $supplier, int $n): array
    {
        return array_map(fn ($i) => $this->makeProduct($supplier, ['name' => "Repuesto {$i}", 'status' => SupplierProduct::DRAFT]), range(1, $n));
    }

    public function test_defaults_are_free_with_twenty_publications_and_premium_with_a_thousand_and_nothing_is_sold(): void
    {
        $settings = MarketplaceSettings::current();
        $this->assertSame([20, 1000, false, null, ['AR']], [$settings->freeLimit(), $settings->premiumLimit(), $settings->sellsPremium(), $settings->premium_price, $settings->billing_countries]);
        // Destacados preparados (5.000 / 15.000) pero apagados hasta confirmarlos.
        $this->assertSame(['5000.00', '15000.00', false], [$settings->featured_price_7, $settings->featured_price_30, $settings->featured_enabled]);
        $this->assertSame([], $settings->featuredOffers());

        [$supplier, $user] = $this->makeSupplier();
        $this->assertSame(SupplierStanding::FREE, $supplier->standing());
        $this->actingAs($user)->get(route('suppliers.billing'))->assertOk()->assertSee('Plan gratuito')->assertSee('0 de 20 productos publicados')
            ->assertSee('Premium todavía no está disponible')->assertDontSee('Contratar Premium con Mercado Pago')->assertDontSee('prueba gratuita');

        // Los términos muestran los límites de la configuración, no números fijos.
        $this->limits(25, 1500);
        $this->get(route('legal.terms'))->assertSee('(hoy, 25)')->assertSee('hasta 1.500 productos');
    }

    public function test_the_free_limit_counts_published_products_only_and_keeps_extra_ones_as_drafts(): void
    {
        $this->limits(3);
        [$supplier, $user] = $this->makeSupplier();
        $stock = $this->makeProduct($supplier, ['name' => 'Cable 10.000 m', 'availability' => 'in_stock']); // una publicación, sin importar el stock
        $hidden = $this->makeProduct($supplier, ['name' => 'Oculto']);
        $hidden->forceFill(['hidden_by_admin' => true])->save(); // oculto por moderación: no cuenta
        $this->makeProduct($supplier, ['name' => 'Retirado', 'status' => SupplierProduct::RETIRED]);
        [$a, $b, $c] = $this->drafts($supplier, 3);
        $this->actingAs($user);

        $this->post(route('suppliers.products.status', $a->id), ['estado' => 'published'])->assertSessionHasNoErrors();
        $this->post(route('suppliers.products.status', $b->id), ['estado' => 'published'])->assertSessionHasNoErrors();
        $this->assertSame(3, PublicationLimits::counted($supplier->id)->count());

        // Lleno: mensaje claro; el producto queda guardado como borrador y se pueden crear más borradores.
        $this->post(route('suppliers.products.status', $c->id), ['estado' => 'published'])->assertSessionHasErrors(['estado' => PublicationLimits::fullMessage($supplier, 3)]);
        $this->assertSame(SupplierProduct::DRAFT, $c->fresh()->status);
        $this->post(route('suppliers.products.store'), ['name' => 'Nuevo', 'category' => 'doors', 'price_mode' => 'quote', 'availability' => 'in_stock'])->assertRedirect();
        $this->assertSame(SupplierProduct::DRAFT, SupplierProduct::latest('id')->value('status'));
        $this->get(route('suppliers.products.index'))->assertSee('3 de 3 productos publicados')->assertSee('0 lugares libres');

        // Pausar uno libera un lugar.
        $this->post(route('suppliers.products.status', $stock->id), ['estado' => 'paused']);
        $this->post(route('suppliers.products.status', $c->id), ['estado' => 'published'])->assertSessionHasNoErrors();
        $this->assertSame(3, PublicationLimits::counted($supplier->id)->count());
    }

    public function test_the_limit_is_counted_with_the_supplier_row_locked(): void
    {
        $this->limits(2);
        [$supplier] = $this->makeSupplier();
        [$a, $b] = $this->drafts($supplier, 2);
        $this->makeProduct($supplier, ['name' => 'Ya publicado']);

        // El conteo corre dentro de la transacción, después de bloquear la fila del proveedor
        // (en MySQL, SELECT … FOR UPDATE: un segundo pedido espera y vuelve a contar).
        DB::enableQueryLog();
        app(PublicationLimits::class)->publish($a);
        $queries = collect(DB::getQueryLog())->pluck('query')->values();
        $lock = $queries->search(fn ($q) => preg_match('/from [`"]suppliers[`"]/', $q) && (DB::getDriverName() === 'sqlite' || str_contains($q, 'for update')));
        $count = $queries->search(fn ($q) => str_contains($q, 'count(*)') && str_contains($q, 'supplier_products'));
        $this->assertNotFalse($lock);
        $this->assertGreaterThan($lock, $count);
        $this->expectException(ValidationException::class);
        app(PublicationLimits::class)->publish($b);
    }

    public function test_premium_raises_the_limit_and_when_it_ends_extra_products_go_out_of_the_limit_without_losing_anything(): void
    {
        $this->limits(2, 10);
        [$supplier, $user] = $this->makeSupplier();
        $this->premium($supplier);
        $products = $this->drafts($supplier, 5);
        $this->actingAs($user);
        foreach ($products as $p) {
            $this->post(route('suppliers.products.status', $p->id), ['estado' => 'published'])->assertSessionHasNoErrors();
        }
        $this->assertSame(5, PublicationLimits::counted($supplier->id)->count());
        Artisan::call('marketplace:tick'); // registra el plan actual (Premium)

        // Elige qué conservar (hasta el límite gratuito).
        $this->post(route('suppliers.products.keep', $products[3]->id), ['conservar' => 1])->assertSessionHasNoErrors();
        $this->post(route('suppliers.products.keep', $products[4]->id), ['conservar' => 1])->assertSessionHasNoErrors();
        $this->post(route('suppliers.products.keep', $products[0]->id), ['conservar' => 1])->assertSessionHasErrors('estado');

        // Premium vence: quedan publicados los elegidos; el resto, fuera del límite (no es moderación y no se borra nada).
        $this->travel(32)->days();
        $this->assertSame(SupplierStanding::FREE, $supplier->fresh()->standing());
        Artisan::call('marketplace:tick');
        Artisan::call('marketplace:tick');
        $this->assertEqualsCanonicalizing([$products[3]->id, $products[4]->id], PublicationLimits::counted($supplier->id)->pluck('id')->all());
        $this->assertSame(3, SupplierProduct::where('status', SupplierProduct::OVER_LIMIT)->count());
        $this->assertSame(0, SupplierProduct::where('hidden_by_admin', true)->count());
        $this->assertSame(5, SupplierProduct::count());
        $this->assertSame(1, $user->notifications()->where('data->title', 'Terminó tu Premium')->count());
        $this->assertSame(1, $user->notifications()->where('data->title', 'Algunos productos quedaron fuera del límite')->count());
        $this->assertFalse($products[0]->fresh()->isVisible());
        $this->get(route('suppliers.products.edit', $products[0]->id))->assertSee('Fuera del límite de tu plan')->assertDontSee('Oculto por moderación');

        // Puede cambiar cuál publica: pausa uno y publica otro; sin lugar, no.
        $this->post(route('suppliers.products.status', $products[3]->id), ['estado' => 'paused']);
        $this->post(route('suppliers.products.status', $products[0]->id), ['estado' => 'published'])->assertSessionHasNoErrors();
        $this->post(route('suppliers.products.status', $products[1]->id), ['estado' => 'published'])->assertSessionHasErrors('estado');

        // Reactiva Premium: vuelve a publicar lo que estaba fuera del límite, sin perder nada.
        $this->premium($supplier);
        $this->post(route('suppliers.products.restore'))->assertSessionHasNoErrors();
        $this->assertSame(4, PublicationLimits::counted($supplier->id)->count());
        $this->assertSame(0, SupplierProduct::where('status', SupplierProduct::OVER_LIMIT)->count());
    }

    public function test_when_premium_ends_the_catalog_respects_the_free_limit_before_the_scheduled_task_runs(): void
    {
        $this->limits(2, 10);
        [$supplier] = $this->makeSupplier();
        $this->premium($supplier);
        $keep = $this->makeProduct($supplier, ['name' => 'Elegido', 'published_at' => now()->subDays(50)]);
        $keep->forceFill(['keep_on_free' => true])->save();
        $old = $this->makeProduct($supplier, ['name' => 'Viejo', 'published_at' => now()->subDays(40)]);
        $recent = $this->makeProduct($supplier, ['name' => 'Reciente', 'published_at' => now()->subDays(1)]);
        $mid = $this->makeProduct($supplier, ['name' => 'Medio', 'published_at' => now()->subDays(20)]);
        $this->assertSame(4, SupplierProduct::visible()->count());

        // Vence Premium y todavía no corrió marketplace:tick: el catálogo ya muestra solo 2, los mismos que dejará la tarea.
        $this->travel(32)->days();
        $this->assertSame(4, PublicationLimits::counted($supplier->id)->count()); // estados sin tocar todavía
        $visible = SupplierProduct::visible()->pluck('id')->all();
        $this->assertEqualsCanonicalizing([$keep->id, $recent->id], $visible);
        $this->assertFalse($old->fresh()->isVisible());
        $a = $this->makeTenant();
        try {
            app(MarketplaceRequests::class)->create($a['admin'], $supplier->id, [$mid->id => 1], ['contact_name' => 'Ana', 'contact_email' => 'ana@e.test'], 'k');
            $this->fail('Se pidió un producto que excede el límite del plan');
        } catch (ValidationException) {
        }

        Artisan::call('marketplace:tick');
        $this->assertEqualsCanonicalizing($visible, PublicationLimits::counted($supplier->id)->pluck('id')->all());
        $this->assertEqualsCanonicalizing($visible, SupplierProduct::visible()->pluck('id')->all());
        // La moderación manda: ocultar uno visible no hace aparecer otro que quedó fuera del límite.
        $recent->forceFill(['hidden_by_admin' => true])->save();
        $this->assertSame([$keep->id], SupplierProduct::visible()->pluck('id')->all());
    }

    public function test_without_a_choice_the_rule_is_deterministic_and_featured_products_stay(): void
    {
        $this->limits(2, 10);
        [$supplier] = $this->makeSupplier();
        $old = $this->makeProduct($supplier, ['name' => 'Viejo', 'published_at' => now()->subDays(30)]);
        $mid = $this->makeProduct($supplier, ['name' => 'Medio', 'published_at' => now()->subDays(20)]);
        $new = $this->makeProduct($supplier, ['name' => 'Nuevo', 'published_at' => now()->subDays(10)]);
        $featured = $this->makeProduct($supplier, ['name' => 'Destacado', 'published_at' => now()->subDays(40)]);
        FeaturedPlacement::create(['supplier_id' => $supplier->id, 'supplier_product_id' => $featured->id, 'product_name' => 'Destacado', 'source' => 'purchase', 'days' => 7,
            'amount' => 5000, 'currency' => 'ARS', 'status' => 'active', 'starts_at' => now()->subDay(), 'ends_at' => now()->addDays(6)]);

        // Regla «más recientes»: primero el destacado vigente, después el publicado más recientemente.
        $this->assertSame(2, app(PublicationLimits::class)->enforce($supplier));
        $this->assertEqualsCanonicalizing([$new->id, $featured->id], PublicationLimits::counted($supplier->id)->pluck('id')->all());
        $this->assertSame([SupplierProduct::OVER_LIMIT, SupplierProduct::OVER_LIMIT], [$old->fresh()->status, $mid->fresh()->status]);

        // Con la regla «más antiguas», el mismo punto de partida da el otro resultado (siempre igual).
        SupplierProduct::whereIn('id', [$old->id, $mid->id])->update(['status' => SupplierProduct::PUBLISHED]);
        MarketplaceSettings::current()->update(['over_limit_rule' => 'oldest']);
        app(PublicationLimits::class)->enforce($supplier);
        $this->assertEqualsCanonicalizing([$old->id, $featured->id], PublicationLimits::counted($supplier->id)->pluck('id')->all());
    }

    public function test_moderation_wins_over_payment_and_restoring_a_product_respects_the_limit(): void
    {
        $this->limits(1);
        [$supplier, $user] = $this->makeSupplier(Supplier::SUSPENDED);
        config(['services.mercadopago.access_token' => 'APP_USR-TEST']);
        MarketplaceSettings::current()->update(['premium_enabled' => true, 'premium_price' => 25000]);
        $this->premium($supplier);

        // Pagó, pero está suspendido: no opera ni puede contratar Premium.
        $this->assertSame(SupplierStanding::SUSPENDED, $supplier->fresh()->standing());
        $this->actingAs($user)->post(route('suppliers.billing.subscribe'))->assertSessionHasErrors('suscripcion');

        // Restaurar una publicación oculta no publica de más: queda fuera del límite.
        $supplier->forceFill(['status' => Supplier::APPROVED])->save();
        SupplierSubscription::query()->delete();
        $this->makeProduct($supplier, ['name' => 'Publicado']);
        $hidden = $this->makeProduct($supplier, ['name' => 'Oculto']);
        $hidden->forceFill(['hidden_by_admin' => true, 'hidden_reason' => 'Fotos'])->save();
        auth()->logout();
        $this->actingInPanel(User::factory()->superAdmin()->create());
        Livewire::test(ListSupplierProducts::class)->callTableAction('unhide', $hidden);
        $this->assertSame([false, SupplierProduct::OVER_LIMIT], [$hidden->fresh()->hidden_by_admin, $hidden->fresh()->status]);
        $this->assertSame(1, PublicationLimits::counted($supplier->id)->count());
    }

    public function test_international_suppliers_publish_for_free_and_never_see_payments(): void
    {
        config(['services.mercadopago.access_token' => 'APP_USR-TEST']);
        MarketplaceSettings::current()->update(['premium_enabled' => true, 'premium_price' => 25000, 'featured_enabled' => true]);
        [$supplier, $user] = $this->makeSupplier();
        $supplier->forceFill(['country' => 'CL', 'currency' => 'CLP'])->save();
        $product = $this->makeProduct($supplier, ['name' => 'Rodamiento']);

        $this->assertSame(SupplierStanding::FREE, $supplier->fresh()->standing());
        $this->assertTrue($product->fresh()->isVisible());
        $this->actingAs($user->fresh())->get(route('suppliers.billing'))->assertOk()->assertSee('solo se puede contratar desde Argentina')
            ->assertDontSee('Contratar Premium con Mercado Pago')->assertDontSee('ARS 25.000');
        $this->post(route('suppliers.billing.subscribe'))->assertSessionHasErrors('suscripcion');
        $this->get(route('suppliers.featured'))->assertSee('solo están disponibles para proveedores registrados en Argentina')->assertDontSee('Pagar con Mercado Pago');
        $this->post(route('suppliers.featured.purchase'), ['producto' => $product->id, 'dias' => 7])->assertSessionHasErrors('destacado');

        // El país no se cambia desde el perfil.
        $this->put(route('suppliers.profile.update'), ['name' => 'Otro', 'contact_email' => 'x@y.test', 'contact_phone' => '+56 9 1234 5678', 'coverage' => 'Santiago', 'categories' => ['doors'], 'country' => 'AR', 'currency' => 'ARS']);
        $this->assertSame(['CL', 'CLP'], [$supplier->fresh()->country, $supplier->fresh()->currency]);

        // Si el SuperAdmin quita Argentina de los países con cobro, tampoco se ofrece allí.
        MarketplaceSettings::current()->update(['billing_countries' => []]);
        $this->assertFalse(MarketplaceSettings::current()->chargesIn('AR'));
    }

    public function test_a_supplier_can_not_touch_another_suppliers_products_requests_or_settings(): void
    {
        [$mine, $me] = $this->makeSupplier();
        [$other, $otherUser] = $this->makeSupplier(Supplier::APPROVED, 'Competidor');
        $theirs = $this->makeProduct($other, ['name' => 'Ajeno', 'status' => SupplierProduct::DRAFT]);
        $a = $this->makeTenant();
        $visible = $this->makeProduct($other, ['name' => 'Visible']);
        $request = app(MarketplaceRequests::class)->create($a['admin'], $other->id, [$visible->id => 1], ['contact_name' => 'Ana', 'contact_email' => 'ana@e.test'], 'k');
        auth()->logout();
        $this->actingAs($me);

        $this->get(route('suppliers.products.edit', $theirs->id))->assertNotFound();
        $this->post(route('suppliers.products.status', $theirs->id), ['estado' => 'published'])->assertNotFound();
        $this->post(route('suppliers.products.keep', $theirs->id), ['conservar' => 1])->assertNotFound();
        $this->put(route('suppliers.products.update', $theirs->id), ['name' => 'X', 'category' => 'doors', 'price_mode' => 'quote', 'availability' => 'in_stock'])->assertNotFound();
        $this->post(route('suppliers.featured.purchase'), ['producto' => $visible->id, 'dias' => 7])->assertNotFound();
        $this->get(route('suppliers.requests.show', $request->reference))->assertNotFound();
        $this->post(route('suppliers.requests.comment', $request->reference), ['comentario' => 'Hola'])->assertNotFound();
        $this->get(MarketplaceSettingsPage::getUrl())->assertRedirect();
        $this->assertSame([SupplierProduct::DRAFT, 'Ajeno'], [$theirs->fresh()->status, $theirs->fresh()->name]);
        $this->assertSame(0, $request->events()->where('kind', 'note')->count());
    }

    public function test_the_plan_never_blocks_operations_of_a_free_supplier(): void
    {
        $this->limits(1);
        [$supplier, $supplierUser] = $this->makeSupplier();
        $product = $this->makeProduct($supplier);
        $a = $this->makeTenant();
        $service = app(MarketplaceRequests::class);
        $request = $service->create($a['admin'], $supplier->id, [$product->id => 1], ['contact_name' => 'Ana', 'contact_email' => 'ana@e.test'], 'k');
        $service->quote($supplierUser, $request->fresh(), [$request->items()->value('id') => 10], 7, null);
        $service->buyerMove($a['admin'], $request->fresh(), MarketplaceRequest::ACCEPTED, null, $request->fresh()->quoteVersion());
        $service->buyerMove($a['admin'], $request->fresh(), MarketplaceRequest::COMPLETED);
        $this->assertSame(MarketplaceRequest::COMPLETED, $request->fresh()->status);

        $this->actingInPanel($a['admin']);
        Livewire::test(PartsCatalog::class)->assertSee($product->name);
    }
}
