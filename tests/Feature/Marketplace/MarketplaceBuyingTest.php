<?php

namespace Tests\Feature\Marketplace;

use App\Enums\PermissionArea;
use App\Filament\Pages\Marketplace\PartsCatalog;
use App\Filament\Pages\Marketplace\PartsRequests;
use App\Models\Marketplace\MarketplaceFavorite;
use App\Models\Marketplace\MarketplaceRequest;
use App\Models\Marketplace\Supplier;
use App\Models\User;
use App\Notifications\Marketplace\BuyerRequestUpdate;
use App\Notifications\Marketplace\SupplierRequestUpdate;
use App\Services\Marketplace\MarketplaceRequests;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithTenants;
use Tests\Feature\Marketplace\Concerns\MakesMarketplace;
use Tests\TestCase;

/**
 * Compra de repuestos: catálogo solo con lo visible, permisos de quién compra,
 * pedido de cotización sin duplicados y con precios del servidor, cotización
 * del proveedor, aceptación y recepción. Nada se marca como pagado.
 */
class MarketplaceBuyingTest extends TestCase
{
    use InteractsWithTenants, MakesMarketplace, RefreshDatabase;

    private function staff(array $tenant, array $permissions): User
    {
        $user = User::factory()->create(['company_id' => $tenant['company']->id, 'role' => User::ROLE_STAFF]);
        $user->forceFill(['permissions' => $permissions])->save();

        return $user->fresh();
    }

    public function test_the_catalog_only_shows_visible_products_of_approved_suppliers(): void
    {
        [$ok] = $this->makeSupplier(Supplier::APPROVED, 'Aprobado');
        [$pending] = $this->makeSupplier(Supplier::PENDING, 'Pendiente');
        $visible = $this->makeProduct($ok, ['name' => 'Cable de acero visible']);
        $this->makeProduct($ok, ['name' => 'Borrador escondido', 'status' => 'draft']);
        $this->makeProduct($ok, ['name' => 'Pausado escondido', 'status' => 'paused']);
        // Destacado pero oculto por moderación: sigue oculto.
        $this->makeProduct($ok, ['name' => 'Oculto destacado', 'hidden_by_admin' => true, 'featured_until' => now()->addMonth()]);
        $this->makeProduct($pending, ['name' => 'De proveedor pendiente']);
        $featured = $this->makeProduct($ok, ['name' => 'Polea destacada', 'featured_until' => now()->addWeek()]);

        $a = $this->makeTenant();
        $this->actingInPanel($a['admin']);
        $page = Livewire::test(PartsCatalog::class)->assertOk()
            ->assertSee('Cable de acero visible')->assertSee('Polea destacada')->assertSee('declara cada proveedor')
            ->assertDontSee('Borrador escondido')->assertDontSee('Pausado escondido')->assertDontSee('Oculto destacado')->assertDontSee('De proveedor pendiente');
        // Destacado primero.
        $this->assertLessThan(strpos($page->html(), 'Cable de acero visible'), strpos($page->html(), 'Polea destacada'));

        // Búsqueda y filtros.
        Livewire::test(PartsCatalog::class)->set('q', 'polea')->assertSee('Polea destacada')->assertDontSee('Cable de acero visible');
        Livewire::test(PartsCatalog::class)->set('q', '%')->assertDontSee('Polea destacada');
        // Detalle: compatibilidad «declarada», y un producto no visible da 404.
        Livewire::test(PartsCatalog::class, ['producto' => $visible->id])->assertSee('Compatibilidad declarada por el proveedor')->assertSee('Ascento no la verifica');
        $this->get(PartsCatalog::getUrl(['producto' => $featured->id + 100]))->assertNotFound();
    }

    public function test_only_the_owner_and_staff_with_stock_buy(): void
    {
        $a = $this->makeTenant();
        $viewer = $this->staff($a, [PermissionArea::Stock->key('view')]);
        $manager = $this->staff($a, [PermissionArea::Stock->key('manage')]);
        $other = $this->staff($a, [PermissionArea::Clients->key('manage')]);
        $super = User::factory()->superAdmin()->create();
        [$supplier] = $this->makeSupplier();
        $product = $this->makeProduct($supplier);

        $this->actingInPanel($a['admin'])->get(PartsCatalog::getUrl())->assertOk();
        $this->actingInPanel($manager)->get(PartsRequests::getUrl())->assertOk();
        $this->actingInPanel($other)->get(PartsCatalog::getUrl())->assertForbidden();
        $this->actingInPanel($super)->get(PartsCatalog::getUrl())->assertForbidden();

        // Con «ver»: mira, pero no pide.
        $this->actingInPanel($viewer);
        Livewire::test(PartsCatalog::class)->assertSee($product->name)->assertDontSee('data-mk-add', false)->call('addToRequest', $product->id)->assertForbidden();
    }

    public function test_a_request_is_created_once_with_server_prices_and_only_for_that_supplier(): void
    {
        [$supplier, $supplierUser] = $this->makeSupplier();
        [$otherSupplier] = $this->makeSupplier(Supplier::APPROVED, 'Ajeno');
        $product = $this->makeProduct($supplier, ['price' => 1000]);
        $foreign = $this->makeProduct($otherSupplier, ['name' => 'Producto ajeno']);
        $a = $this->makeTenant();
        $this->actingInPanel($a['admin']);

        Livewire::test(PartsCatalog::class)->set('quantity', 3)->call('addToRequest', $product->id)->assertNotified('Agregado al pedido');
        $page = Livewire::test(PartsRequests::class)->assertSee($product->name)->assertSee('Pedir cotización a '.$supplier->name)
            ->set('contact.contact_phone', '11 4444-5555')->call('send', $supplier->id)->assertHasNoErrors();

        $request = MarketplaceRequest::sole();
        $item = $request->items()->sole();
        $this->assertSame([MarketplaceRequest::SENT, $a['company']->id, $supplier->id, 3, '1000.00'], [$request->status, $request->company_id, $request->supplier_id, $item->quantity, $item->listed_price]);
        $this->assertSame(1, $supplierUser->notifications()->where('type', SupplierRequestUpdate::class)->count());
        // El pedido en armado se vació.
        Livewire::test(PartsRequests::class)->assertSee('No sumaste productos todavía');

        // Misma clave (doble clic / reenvío): el mismo pedido.
        $service = app(MarketplaceRequests::class);
        $contact = ['contact_name' => 'Ana', 'contact_email' => 'ana@empresa.test'];
        $first = $service->create($a['admin'], $supplier->id, [$product->id => 1], $contact, 'misma-clave');
        $again = $service->create($a['admin'], $supplier->id, [$product->id => 9], $contact, 'misma-clave');
        $this->assertSame($first->id, $again->id);
        $this->assertSame(2, MarketplaceRequest::count());

        // Un producto de OTRO proveedor no entra en este pedido; solo ese: error.
        $this->expectException(ValidationException::class);
        $service->create($a['admin'], $supplier->id, [$foreign->id => 1], $contact, 'otra-clave');
    }

    public function test_the_full_quote_flow_without_payments_and_with_isolation(): void
    {
        [$supplier, $supplierUser] = $this->makeSupplier();
        [, $otherSupplierUser] = $this->makeSupplier(Supplier::APPROVED, 'Competidor');
        $p1 = $this->makeProduct($supplier, ['price' => 1000]);
        $p2 = $this->makeProduct($supplier, ['name' => 'Botonera', 'price_mode' => 'quote', 'price' => null]);
        $a = $this->makeTenant();
        $a['company']->forceFill(['cuit' => '30-71234567-1', 'email' => 'interno@empresa.test'])->saveQuietly();
        $service = app(MarketplaceRequests::class);
        $request = $service->create($a['admin'], $supplier->id, [$p1->id => 2, $p2->id => 1], ['contact_name' => 'Ana Compras', 'contact_email' => 'compras@empresa.test'], 'k1');
        [$i1, $i2] = $request->items()->orderBy('id')->get()->all();
        auth()->logout();

        // El proveedor ve solo lo compartido: ni el CUIT, ni el correo de la empresa, ni sus edificios.
        $this->actingAs($supplierUser)->get(route('suppliers.requests.show', $request->reference))->assertOk()
            ->assertSee('Ana Compras')->assertSee($a['company']->name)
            ->assertDontSee('30-71234567-1')->assertDontSee('interno@empresa.test')->assertDontSee($a['building']->name);
        $this->actingAs($otherSupplierUser)->get(route('suppliers.requests.show', $request->reference))->assertNotFound();
        $this->post(route('suppliers.requests.quote', $request->reference), ['precios' => [$i1->id => 1]])->assertNotFound();

        // Cotiza: el total lo calcula el servidor (un «total» enviado se ignora).
        $this->actingAs($supplierUser)->post(route('suppliers.requests.quote', $request->reference), ['precios' => [$i1->id => '900', $i2->id => '5000.5'], 'validez' => 7, 'nota' => 'Entrega en 48 h', 'quoted_total' => 1])->assertSessionHasNoErrors();
        $request->refresh();
        $this->assertSame([MarketplaceRequest::QUOTED, '6800.50'], [$request->status, $request->quoted_total]);
        $this->post(route('suppliers.requests.quote', $request->reference), ['precios' => [$i1->id => '-5']])->assertSessionHasErrors();
        $this->assertSame(1, $a['admin']->notifications()->where('type', BuyerRequestUpdate::class)->count());

        // Otra empresa no lo ve.
        auth()->logout();
        $b = $this->makeTenant();
        $this->actingInPanel($b['admin'])->get(PartsRequests::getUrl(['pedido' => $request->id]))->assertNotFound();

        // El comprador acepta y después confirma que lo recibió.
        $this->actingInPanel($a['admin']->fresh());
        Livewire::test(PartsRequests::class, ['pedido' => $request->id])->assertSee('Total cotizado')->assertSee('no un cobro')
            ->call('move', 'completed')->assertHasErrors('estado') // primero hay que aceptar
            ->call('move', 'accepted')->assertHasNoErrors()->call('move', 'completed')->assertHasNoErrors();
        $this->assertSame(MarketplaceRequest::COMPLETED, $request->fresh()->status);
        $this->assertSame(1, $supplier->completedOperations());
        $this->assertSame(['sent', 'quoted', 'accepted', 'completed'], $request->events()->pluck('to')->all());

        // Ya completado: el proveedor no lo puede volver a cotizar.
        $this->expectException(ValidationException::class);
        $service->quote($supplierUser, $request->fresh(), [$i1->id => 1], null, null);
    }

    public function test_nothing_is_ever_marked_paid_and_expired_or_suspended_quotes_are_not_accepted(): void
    {
        $this->assertNotContains('paid', array_keys(MarketplaceRequest::STATUSES));
        $this->assertFalse(Schema::hasColumn('marketplace_requests', 'paid_at'));

        [$supplier, $supplierUser] = $this->makeSupplier();
        $product = $this->makeProduct($supplier);
        $a = $this->makeTenant();
        $service = app(MarketplaceRequests::class);
        $request = $service->create($a['admin'], $supplier->id, [$product->id => 1], ['contact_name' => 'Ana', 'contact_email' => 'ana@e.test'], 'k');
        $service->quote($supplierUser, $request, [$request->items()->value('id') => 10], 1, null);

        $this->travel(3)->days();
        try {
            $service->buyerMove($a['admin'], $request->fresh(), MarketplaceRequest::ACCEPTED);
            $this->fail('Aceptó una cotización vencida');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('venció', $e->getMessage());
        }

        $service->quote($supplierUser, $request->fresh(), [$request->items()->value('id') => 10], 10, null);
        $supplier->forceFill(['status' => Supplier::SUSPENDED])->save();
        $this->expectException(ValidationException::class);
        $service->buyerMove($a['admin'], $request->fresh(), MarketplaceRequest::ACCEPTED);
    }

    public function test_favorites_and_repeat_orders_belong_to_the_company(): void
    {
        [$supplier] = $this->makeSupplier();
        $product = $this->makeProduct($supplier);
        $gone = $this->makeProduct($supplier, ['name' => 'Ya no está']);
        $a = $this->makeTenant();
        $request = app(MarketplaceRequests::class)->create($a['admin'], $supplier->id, [$product->id => 2, $gone->id => 1], ['contact_name' => 'Ana', 'contact_email' => 'ana@e.test'], 'k');
        $gone->forceFill(['status' => 'retired'])->save();

        $this->actingInPanel($a['admin']);
        Livewire::test(PartsCatalog::class)->call('toggleFavorite', $product->id)->set('favoritos', true)->assertSee($product->name);
        $this->assertSame(1, MarketplaceFavorite::where('company_id', $a['company']->id)->count());
        // Repetir: vuelve lo que sigue disponible, con sus cantidades.
        Livewire::test(PartsRequests::class, ['pedido' => $request->id])->call('repeat')->assertNotified('Agregamos 1 productos a tu pedido');
        $this->assertSame([$product->id => 2], session('marketplace.cart.'.$a['company']->id));

        auth()->logout();
        $b = $this->makeTenant();
        $this->actingInPanel($b['admin']);
        Livewire::test(PartsCatalog::class)->set('favoritos', true)->assertDontSee($product->name);
    }
}
