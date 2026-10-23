<?php

namespace Tests\Feature\Marketplace;

use App\Filament\Pages\Marketplace\PartsCatalog;
use App\Filament\Pages\Marketplace\PartsRequests;
use App\Http\Controllers\Marketplace\PublicCatalogController;
use App\Models\Marketplace\MarketplaceRequest;
use App\Models\Marketplace\MarketplaceSettings;
use App\Models\Marketplace\Supplier;
use App\Models\Marketplace\SupplierProduct;
use App\Models\Marketplace\SupplierProductPhoto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Concerns\InteractsWithTenants;
use Tests\Feature\Marketplace\Concerns\MakesMarketplace;
use Tests\TestCase;

/**
 * Catálogo de repuestos sin cuenta: solo productos visibles, el perfil
 * comercial del proveedor (sin correo, teléfono ni identificación fiscal),
 * nada de pedidos ni compradores, y lo que necesita cuenta sigue protegido.
 */
class PublicCatalogTest extends TestCase
{
    use InteractsWithTenants, MakesMarketplace, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');
    }

    private function photo(SupplierProduct $product): SupplierProductPhoto
    {
        $path = 'marketplace/'.$product->supplier_id.'/'.Str::random(8).'.jpg';
        Storage::disk('local')->put($path, 'jpeg-bytes');
        Storage::disk('local')->put(str_replace('.jpg', '-thumb.jpg', $path), 'thumb-bytes');

        return SupplierProductPhoto::create(['supplier_product_id' => $product->id, 'supplier_id' => $product->supplier_id, 'path' => $path, 'thumb_path' => str_replace('.jpg', '-thumb.jpg', $path), 'position' => 1]);
    }

    /** @return array{0: Supplier, 1: SupplierProduct} */
    private function catalog(): array
    {
        [$supplier] = $this->makeSupplier(Supplier::APPROVED, 'Repuestos Sur');
        $supplier->forceFill(['contact_email' => 'privado-ventas@sur.test', 'contact_phone' => '11 9999-1234', 'tax_id' => '30-71234567-1', 'description' => 'Repuestos de puertas desde 1990.', 'website' => 'https://sur.test'])->save();
        $product = $this->makeProduct($supplier, ['name' => 'Operador de puerta VF5', 'brand' => 'Fermator', 'model' => 'VVVF5', 'part_number' => 'FER-VF5', 'description' => 'Operador completo.']);

        return [$supplier, $product];
    }

    public function test_a_visitor_browses_searches_and_filters_without_an_account(): void
    {
        [$supplier, $product] = $this->catalog();
        $this->makeProduct($supplier, ['name' => 'Botonera de cabina', 'category' => 'buttons', 'brand' => 'Otis', 'part_number' => 'OT-BC8', 'availability' => 'to_order', 'price_mode' => 'quote', 'price' => null]);

        $this->get(route('marketplace.catalog'))->assertOk()->assertSee('Operador de puerta VF5')->assertSee('Botonera de cabina')
            ->assertSee('ARS 150.000')->assertSee('A cotizar')->assertSee('<meta name="robots" content="index, follow">', false);

        // Búsqueda por código y filtros: lo que no coincide no aparece y la página no se indexa.
        $this->get(route('marketplace.catalog', ['q' => 'FER-VF5']))->assertOk()->assertSee('Operador de puerta VF5')->assertDontSee('Botonera de cabina')
            ->assertSee('noindex, follow', false);
        $this->get(route('marketplace.catalog', ['rubro' => 'buttons']))->assertSee('Botonera de cabina')->assertDontSee('Operador de puerta VF5');
        $this->get(route('marketplace.catalog', ['marca' => 'Otis', 'disponibilidad' => 'to_order', 'precio' => 'quote']))->assertSee('Botonera de cabina')->assertDontSee('Operador de puerta VF5');
        // Valores inventados se ignoran (no rompen ni agregan nada).
        $this->get(route('marketplace.catalog', ['rubro' => "x' OR 1=1", 'disponibilidad' => 'nada', 'page' => 'abc']))->assertOk()->assertSee('Operador de puerta VF5');
        // Sin resultados: estado vacío claro.
        $this->get(route('marketplace.catalog', ['q' => 'inexistente-zzz']))->assertOk()->assertSee('data-empty-state', false)->assertSee('No encontramos repuestos');
    }

    public function test_the_product_page_shows_public_data_with_a_canonical_url_and_never_private_data(): void
    {
        [$supplier, $product] = $this->catalog();
        $photo = $this->photo($product);
        $a = $this->makeTenant();
        MarketplaceRequest::forceCreate(['reference' => 'R-ABCDEFGH', 'company_id' => $a['company']->id, 'supplier_id' => $supplier->id,
            'contact_name' => 'Comprador Secreto', 'contact_email' => 'comprador@privado.test', 'contact_phone' => '11 1111-2222', 'delivery_area' => 'Calle Privada 123',
            'notes' => 'Nota interna del pedido', 'status' => MarketplaceRequest::SENT, 'idempotency_key' => Str::random(40)]);
        auth()->logout();

        // Sin el nombre correcto, redirige (301) a la única dirección del producto.
        $this->get('/marketplace/repuestos/'.$product->id)->assertRedirect(PublicCatalogController::productUrl($product))->assertStatus(301);
        $this->get('/marketplace/repuestos/'.$product->id.'/otro-nombre')->assertStatus(301);

        $html = $this->get(PublicCatalogController::productUrl($product))->assertOk()
            ->assertSee('Operador de puerta VF5')->assertSee('Fermator')->assertSee('VVVF5')->assertSee('FER-VF5')->assertSee('Puertas automáticas de 2 hojas')
            ->assertSee('Compatibilidad declarada')->assertSee('Ascento no la verifica')->assertSee('Repuestos Sur')->assertSee('Zonas:')
            ->assertSee('<link rel="canonical" href="'.PublicCatalogController::productUrl($product).'">', false)
            ->assertSee('"@type":"Product"', false)->assertSee(PublicCatalogController::photoUrl($photo), false)
            ->assertSee('Entrar para pedir cotización')
            ->getContent();

        foreach (['privado-ventas@sur.test', '11 9999-1234', '30-71234567-1', 'Comprador Secreto', 'comprador@privado.test', 'Calle Privada 123', 'Nota interna del pedido', 'R-ABCDEFGH', $photo->path] as $private) {
            $this->assertStringNotContainsString($private, $html, $private);
        }

        $supplierHtml = $this->get(route('marketplace.supplier', $supplier->slug))->assertOk()->assertSee('Operador de puerta VF5')->assertSee('Repuestos de puertas desde 1990.')->assertSee('https://sur.test', false)->getContent();
        foreach (['privado-ventas@sur.test', '11 9999-1234', '30-71234567-1', 'Comprador Secreto', 'R-ABCDEFGH'] as $private) {
            $this->assertStringNotContainsString($private, $supplierHtml, $private);
        }
    }

    public function test_hidden_paused_or_suspended_products_and_their_photos_are_not_public(): void
    {
        [$supplier, $product] = $this->catalog();
        $photo = $this->photo($product);
        $url = PublicCatalogController::productUrl($product);

        $this->get(PublicCatalogController::photoUrl($photo))->assertOk()->assertHeader('Cache-Control', 'max-age=600, public');
        $this->get(PublicCatalogController::photoUrl($photo, thumb: true))->assertOk();

        // Oculto por moderación.
        $product->forceFill(['hidden_by_admin' => true])->save();
        $this->get($url)->assertNotFound();
        $this->get(PublicCatalogController::photoUrl($photo))->assertNotFound();
        $this->get(route('marketplace.catalog'))->assertDontSee('Operador de puerta VF5');

        // Pausado o borrador.
        $product->forceFill(['hidden_by_admin' => false, 'status' => SupplierProduct::PAUSED])->save();
        $this->get($url)->assertNotFound();
        $product->forceFill(['status' => SupplierProduct::DRAFT])->save();
        $this->get($url)->assertNotFound();

        // Proveedor suspendido o pendiente: ni el producto, ni el perfil, ni la foto.
        $product->forceFill(['status' => SupplierProduct::PUBLISHED])->save();
        $this->get($url)->assertOk();
        $supplier->forceFill(['status' => Supplier::SUSPENDED])->save();
        $this->get($url)->assertNotFound();
        $this->get(route('marketplace.supplier', $supplier->slug))->assertNotFound();
        $this->get(PublicCatalogController::photoUrl($photo))->assertNotFound();
        $supplier->forceFill(['status' => Supplier::PENDING])->save();
        $this->get($url)->assertNotFound();

        // Fuera del límite del plan: tampoco.
        $supplier->forceFill(['status' => Supplier::APPROVED])->save();
        MarketplaceSettings::current()->update(['free_product_limit' => 1]);
        $second = $this->makeProduct($supplier, ['name' => 'Segundo', 'published_at' => now()->subDay()]);
        $this->assertFalse($second->isVisible());
        $this->get(PublicCatalogController::productUrl($second))->assertNotFound();

        // Una foto inexistente y la ruta privada de fotos siguen sin servir nada a un visitante.
        $this->get(route('marketplace.image', 999999))->assertNotFound();
        $this->get(route('marketplace.photo', $photo->id))->assertRedirect(route('login'));
    }

    public function test_operations_that_need_an_account_stay_protected(): void
    {
        [, $product] = $this->catalog();

        $this->get(PartsCatalog::getUrl(['producto' => $product->id], panel: 'ascensores_app'))->assertRedirect(route('login'));
        $this->get(PartsRequests::getUrl(panel: 'ascensores_app'))->assertRedirect(route('login'));
        $this->post('/proveedores/catalogo', [])->assertRedirect(route('login'));

        // Una empresa con cuenta ve el atajo a su panel en vez de «Entrar».
        $a = $this->makeTenant();
        $this->actingAs($a['admin'])->get(PublicCatalogController::productUrl($product))->assertOk()
            ->assertSee('Pedir cotización en mi panel')->assertDontSee('Entrar para pedir cotización');
    }

    public function test_the_super_admin_can_turn_the_public_catalog_off(): void
    {
        [$supplier, $product] = $this->catalog();
        $photo = $this->photo($product);
        MarketplaceSettings::current()->update(['public_catalog_enabled' => false]);

        $this->get(route('marketplace.catalog'))->assertRedirect(route('marketplace'));
        $this->get(PublicCatalogController::productUrl($product))->assertNotFound();
        $this->get(route('marketplace.supplier', $supplier->slug))->assertNotFound();
        $this->get(PublicCatalogController::photoUrl($photo))->assertNotFound();
        $this->get('/sitemap.xml')->assertOk()->assertDontSee('/marketplace/repuestos/', false);
    }

    public function test_the_sitemap_lists_only_public_pages_and_visible_products(): void
    {
        [$supplier, $product] = $this->catalog();
        $hidden = $this->makeProduct($supplier, ['name' => 'Producto oculto']);
        $hidden->forceFill(['hidden_by_admin' => true])->save();
        [$pending] = $this->makeSupplier(Supplier::PENDING, 'Pendiente SA');
        $this->makeProduct($pending, ['name' => 'De un pendiente']);

        $xml = $this->get('/sitemap.xml')->assertOk()->assertHeader('Content-Type', 'application/xml; charset=UTF-8')->getContent();
        $this->assertStringContainsString('<loc>'.route('marketplace').'</loc>', $xml);
        $this->assertStringContainsString('<loc>'.route('marketplace.catalog').'</loc>', $xml);
        $this->assertStringContainsString('<loc>'.PublicCatalogController::productUrl($product).'</loc>', $xml);
        $this->assertStringContainsString('<loc>'.route('marketplace.supplier', $supplier->slug).'</loc>', $xml);
        $this->assertStringNotContainsString('/repuestos/'.$hidden->id.'/', $xml);
        $this->assertStringNotContainsString($pending->slug, $xml);
        foreach (['/admin', '/portal', '/proveedores/catalogo', '/qr/', 'delivery-notes'] as $private) {
            $this->assertStringNotContainsString($private, $xml, $private);
        }

        $robots = file_get_contents(public_path('robots.txt'));
        foreach (['Disallow: /admin', 'Disallow: /portal', 'Disallow: /proveedores/', 'Allow: /proveedores/registro', 'Sitemap: '] as $line) {
            $this->assertStringContainsString($line, $robots);
        }
    }

    public function test_the_marketplace_page_shows_real_products_and_categories_when_there_are_any(): void
    {
        $this->get(route('marketplace'))->assertOk()->assertDontSee('data-explore', false);

        [, $product] = $this->catalog();
        $this->get(route('marketplace'))->assertOk()->assertSee('data-explore', false)->assertSee('Operador de puerta VF5')
            ->assertSee(route('marketplace.catalog', ['rubro' => 'doors']), false);
    }
}
