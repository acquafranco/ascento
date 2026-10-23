<?php

namespace Tests\Feature\Marketplace;

use App\Models\Marketplace\Supplier;
use App\Models\Marketplace\SupplierProduct;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\InteractsWithTenants;
use Tests\Concerns\PassesRegistrationChallenge;
use Tests\Feature\Marketplace\Concerns\MakesMarketplace;
use Tests\TestCase;

/**
 * «Profesionales y servicios» se retiró: el marketplace es solo de repuestos.
 * Sin rutas, pantallas ni registro de servicios; sus tablas y columnas las
 * borra 2027_01_21 (ServicesCleanupMigrationTest).
 */
class ServicesRetiredTest extends TestCase
{
    use InteractsWithTenants, MakesMarketplace, PassesRegistrationChallenge, RefreshDatabase;

    public function test_no_services_route_page_or_link_is_left(): void
    {
        foreach (['services.index', 'services.show', 'suppliers.service-profile.edit', 'suppliers.service-requests.index'] as $name) {
            $this->assertFalse(Route::has($name), $name);
        }
        $this->get('/profesionales')->assertNotFound();
        $this->get('/profesionales/placas-pereyra')->assertNotFound();
        $this->get('/')->assertOk()->assertDontSee('Profesionales y servicios');
        $this->get(route('legal.terms'))->assertOk()->assertDontSee('Profesionales y servicios')->assertDontSee('id="profesionales"', false);
        $this->get(route('legal.privacy'))->assertOk()->assertDontSee('Perfiles profesionales');

        // Panel: ninguna pantalla ni recurso de servicios, ni para el SuperAdmin.
        $panel = Filament::getPanel('ascensores_app');
        $names = collect(array_merge($panel->getPages(), $panel->getResources()))->map(fn ($c) => class_basename($c));
        $this->assertEmpty($names->filter(fn ($n) => str_contains($n, 'ServiceProfile') || str_contains($n, 'ServiceContact') || str_contains($n, 'ServiceCategor') || $n === 'ServiceRequests'));

        [, $user] = $this->makeSupplier();
        $this->actingAs($user);
        $this->get('/proveedores/perfil-profesional')->assertNotFound();
        $this->get('/proveedores/solicitudes')->assertNotFound();
        $this->post('/proveedores/perfil/vender-productos')->assertNotFound();
        $this->get(route('suppliers.home'))->assertOk()->assertSee('Mi catálogo')->assertDontSee('Perfil profesional')->assertDontSee('Consultas');
        auth()->logout();
        $t = $this->makeTenant();
        $this->actingInPanel($t['admin'])->get('/admin/profesionales')->assertNotFound();
        $this->get('/admin/mi-perfil-profesional')->assertNotFound();
    }

    public function test_registration_always_sells_parts_and_requires_the_tax_id(): void
    {
        $base = ['supplier_name' => 'Repuestos Plata', 'country' => 'AR', 'phone' => '11 4444-0000', 'coverage' => 'CABA',
            'categories' => ['doors'], 'name' => 'Marta', 'email' => 'ventas@plata.test', 'password' => 'Kiosco-Verde-8241', 'password_confirmation' => 'Kiosco-Verde-8241', 'terms' => '1'];

        $this->get(route('suppliers.register'))->assertOk()->assertDontSee('name="offer"', false)->assertDontSee('servicios profesionales');
        // Lo que mande un formulario viejo («solo servicios») se ignora: el CUIT sigue siendo obligatorio.
        $this->post(route('suppliers.register'), [...$this->registrationProof(), ...$base, 'offer' => 'services'])->assertSessionHasErrors(['tax_id']);
        $this->assertSame(0, Supplier::count());

        $this->post(route('suppliers.register'), [...$this->registrationProof(), ...$base, 'offer' => 'services', 'tax_id' => '30-71234567-1'])->assertRedirect(route('suppliers.home'));
        $supplier = Supplier::sole();
        $this->assertFalse(Schema::hasColumn('suppliers', 'sells_products'));
        $this->assertSame([Supplier::PENDING, '30-71234567-1'], [$supplier->status, $supplier->tax_id]);
    }

    public function test_existing_parts_suppliers_keep_their_catalog_and_buyers_keep_ordering(): void
    {
        [$supplier, $user] = $this->makeSupplier();
        $product = $this->makeProduct($supplier);

        $this->actingAs($user)->get(route('suppliers.products.index'))->assertOk()->assertSee($product->name);
        $this->get(route('suppliers.products.edit', $product))->assertOk();
        $this->post(route('suppliers.products.status', $product), ['estado' => SupplierProduct::PAUSED])->assertRedirect();
        $this->assertSame(SupplierProduct::PAUSED, $product->fresh()->status);
        $this->get(route('suppliers.billing'))->assertOk();
        $this->get(route('suppliers.featured'))->assertOk();
    }
}
