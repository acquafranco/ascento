<?php

namespace Tests\Feature\Marketplace;

use App\Models\Company;
use App\Models\Marketplace\MarketplaceRequest;
use App\Models\Marketplace\Supplier;
use App\Models\Marketplace\SupplierProduct;
use App\Models\User;
use App\Services\Companies\CompanyEraser;
use App\Services\Marketplace\MarketplaceRequests;
use App\Services\Marketplace\SupplierEraser;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\Concerns\InteractsWithTenants;
use Tests\Concerns\PassesRegistrationChallenge;
use Tests\Feature\Marketplace\Concerns\MakesMarketplace;
use Tests\TestCase;

/**
 * «Profesionales y servicios» se retiró: el marketplace es solo de repuestos.
 * Sin rutas, pantallas ni registro de servicios; las tablas de su migración
 * se conservan sin uso, y una cuenta anterior «solo servicios» no vende
 * productos hasta cargar su identificación fiscal.
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
        $this->assertTrue($supplier->sellsProducts());
        $this->assertSame([Supplier::PENDING, '30-71234567-1'], [$supplier->status, $supplier->tax_id]);
    }

    public function test_an_earlier_services_only_account_keeps_working_and_enables_parts_with_its_tax_id(): void
    {
        [$supplier, $user] = $this->makeSupplier();
        $supplier->forceFill(['sells_products' => false, 'tax_id' => null])->save();

        $this->actingAs($user);
        foreach (['suppliers.products.index', 'suppliers.requests.index', 'suppliers.billing', 'suppliers.featured'] as $name) {
            $this->get(route($name))->assertRedirect(route('suppliers.profile.edit'));
        }
        $this->get(route('suppliers.home'))->assertOk()->assertDontSee('Mi catálogo');
        $this->get(route('suppliers.profile.edit'))->assertOk()->assertSee('data-enable-products', false);

        $this->post(route('suppliers.profile.enable-products'), ['tax_id' => ''])->assertSessionHasErrors('tax_id');
        $this->post(route('suppliers.profile.enable-products'), ['tax_id' => '30-71234567-1'])->assertRedirect(route('suppliers.products.index'));
        $this->assertTrue($supplier->fresh()->sellsProducts());
        $this->get(route('suppliers.products.index'))->assertOk();
    }

    public function test_existing_parts_suppliers_keep_their_catalog_and_buyers_keep_ordering(): void
    {
        [$supplier, $user] = $this->makeSupplier();
        $product = $this->makeProduct($supplier);
        $this->assertTrue($supplier->fresh()->sellsProducts()); // valor por defecto de la columna

        $this->actingAs($user)->get(route('suppliers.products.index'))->assertOk()->assertSee($product->name);
        $this->get(route('suppliers.products.edit', $product))->assertOk();
        $this->post(route('suppliers.products.status', $product), ['estado' => SupplierProduct::PAUSED])->assertRedirect();
        $this->assertSame(SupplierProduct::PAUSED, $product->fresh()->status);
        $this->get(route('suppliers.billing'))->assertOk();
        $this->get(route('suppliers.featured'))->assertOk();
    }

    public function test_erasing_accounts_still_clears_leftover_service_data_without_touching_orders(): void
    {
        $super = User::factory()->superAdmin()->create();
        $t = $this->makeTenant();
        [$supplier] = $this->makeSupplier();
        $product = $this->makeProduct($supplier);
        $order = app(MarketplaceRequests::class)->create($t['admin'], $supplier->id, [$product->id => 1], ['contact_name' => 'Ana', 'contact_email' => 'ana@e.test'], 'pedido');

        // Datos que pudieron quedar si la función llegó a usarse.
        $profileId = DB::table('service_profiles')->insertGetId(['supplier_id' => $supplier->id, 'slug' => 'placas-sur', 'kind' => 'independent', 'modality' => 'onsite', 'public_name' => 'Placas Sur',
            'description' => 'Reparación de placas', 'status' => 'published', 'created_at' => now(), 'updated_at' => now()]);
        $requestId = DB::table('service_contact_requests')->insertGetId(['reference' => 'P-ABCDEFGH', 'service_profile_id' => $profileId, 'company_id' => $t['company']->id,
            'created_by' => $t['admin']->id, 'profile_name' => 'Placas Sur', 'buyer_name' => $t['company']->name, 'contact_name' => 'Ana', 'contact_email' => 'ana@empresa.test',
            'subject' => 'Placa', 'need' => 'Datos privados', 'status' => 'open', 'idempotency_key' => str_repeat('a', 64), 'last_message_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

        app(CompanyEraser::class)->erase($t['company'], $super, $t['company']->name);
        $this->assertNull(Company::find($t['company']->id));
        $row = DB::table('service_contact_requests')->find($requestId);
        $this->assertSame([null, '', 'closed'], [$row->company_id, $row->contact_email, $row->status]);
        $this->assertStringNotContainsString('Datos privados', $row->need);

        $this->actingAs($super);
        app(SupplierEraser::class)->erase($supplier->fresh(), $super, $supplier->name, 'Pedido del proveedor');
        $this->assertSame(['archived', '—'], [DB::table('service_profiles')->where('id', $profileId)->value('status'), DB::table('service_profiles')->where('id', $profileId)->value('description')]);
        $this->assertNotNull(MarketplaceRequest::find($order->id));
    }
}
