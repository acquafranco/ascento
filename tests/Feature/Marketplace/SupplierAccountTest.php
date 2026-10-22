<?php

namespace Tests\Feature\Marketplace;

use App\Models\Marketplace\Supplier;
use App\Models\Marketplace\SupplierProduct;
use App\Models\User;
use App\Notifications\Marketplace\MarketplaceNotice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\InteractsWithTenants;
use Tests\Concerns\PassesRegistrationChallenge;
use Tests\Feature\Marketplace\Concerns\MakesMarketplace;
use Tests\TestCase;

/**
 * Proveedores de repuestos: registro propio con aprobación, área aislada sin
 * acceso a nada de las empresas, y su catálogo (crear, editar, publicar,
 * pausar, retirar, fotos) sin poder tocar estado de moderación ni dueño.
 */
class SupplierAccountTest extends TestCase
{
    use InteractsWithTenants, MakesMarketplace, PassesRegistrationChallenge, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function register(array $overrides = [])
    {
        return $this->post('/proveedores/registro', [...$this->registrationProof(),
            'supplier_name' => 'Repuestos del Plata', 'country' => 'AR', 'tax_id' => '30-71234567-1', 'phone' => '11 4567-8900',
            'coverage' => 'CABA y GBA', 'categories' => ['doors', 'electrical'], 'brands' => 'Schindler, Otis, schindler',
            'name' => 'Marta Gómez', 'email' => 'Ventas@Plata.test', 'password' => 'Kiosco-Verde-8241', 'password_confirmation' => 'Kiosco-Verde-8241',
            'terms' => '1', ...$overrides]);
    }

    public function test_the_registration_asks_how_to_use_ascento_and_keeps_the_company_flow(): void
    {
        $this->get('/register')->assertOk()->assertSee('¿Cómo querés utilizar Ascento?')
            ->assertSee('Soy una empresa de mantenimiento de ascensores')->assertSee('Soy un proveedor de repuestos')
            ->assertSee(route('suppliers.register'), false)->assertSee('name="company_name"', false);
        $this->get(route('suppliers.register'))->assertOk()->assertSee('data-account-type="supplier"', false)->assertSee('name="tax_id"', false);
        // Los términos explican que Ascento no cobra ni verifica la compatibilidad declarada.
        $this->get(route('legal.terms'))->assertSee('Ascento no vende, no compra, no cobra comisiones por las ventas ni procesa los pagos de los repuestos')->assertSee('declara el proveedor')
            ->assertSee('Condiciones comerciales para proveedores de repuestos')->assertSee('no se borra nada')->assertSee('igual de relevantes');
        $this->get(route('legal.data-deletion'))->assertSee('Cerrar una cuenta de proveedor de repuestos');
        $this->get(route('suppliers.register'))->assertSee('Prueba gratuita de 30 días');
        $this->get(route('legal.privacy'))->assertSee('Proveedores de repuestos')->assertSee('Calculadora del sitio');
    }

    public function test_a_supplier_registers_pending_without_a_company_and_the_team_is_told(): void
    {
        $super = User::factory()->superAdmin()->create();
        // El rol y el estado que mande el formulario se ignoran.
        $this->register(['role' => 'admin', 'status' => 'approved', 'company_id' => 1])->assertRedirect(route('suppliers.home'));

        $user = User::where('email', 'ventas@plata.test')->sole();
        $supplier = $user->supplier;
        $this->assertSame([User::ROLE_SUPPLIER, null, Supplier::PENDING, '30-71234567-1'], [$user->role, $user->company_id, $supplier->status, $supplier->tax_id]);
        $this->assertSame(['Schindler', 'Otis'], $supplier->brands);
        $this->assertSame(['Proveedor nuevo para revisar'], $super->notifications()->where('type', MarketplaceNotice::class)->get()->pluck('data.title')->all());
        $this->assertSame(['Recibimos tu solicitud'], $user->notifications()->get()->pluck('data.title')->all());

        // Sin confirmar el correo: a «Verificá tu email».
        $this->get(route('suppliers.home'))->assertRedirect(route('verification.notice'));

        // La misma identificación fiscal otra vez: no.
        auth()->logout();
        $this->register(['email' => 'otro@plata.test'])->assertSessionHasErrors('tax_id');
    }

    public function test_a_supplier_never_reaches_company_data_or_screens(): void
    {
        $a = $this->makeTenant();
        [$supplier, $user] = $this->makeSupplier(Supplier::APPROVED);

        $this->actingAs($user)->get(route('suppliers.home'))->assertOk()->assertSee($supplier->name);
        // Panel, app del técnico y portal: afuera (a su propia área, nunca a datos ajenos).
        $this->assertNotSame(200, $this->get('/admin')->status());
        $this->assertNotSame(200, $this->get('/admin/clients')->status());
        $this->get(route('dashboard', ['company' => $a['company']->slug]))->assertDontSee($a['building']->name);
        $this->assertNotSame(200, $this->get(route('portal.home'))->status());
        $this->assertNotSame(200, $this->get('/admin/repuestos')->status());

        // Y una empresa no entra al área de proveedores.
        auth()->logout();
        $this->actingAs($a['admin'])->get(route('suppliers.home'))->assertRedirect();
        $this->actingAs($a['technician'])->get(route('suppliers.products.index'))->assertRedirect();
    }

    public function test_the_supplier_manages_only_its_own_catalog(): void
    {
        [$supplier, $user] = $this->makeSupplier(Supplier::PENDING);
        [$other] = $this->makeSupplier(Supplier::APPROVED, 'Otro Proveedor');
        $foreign = $this->makeProduct($other);

        $this->actingAs($user)->post(route('suppliers.products.store'), [
            'name' => 'Placa de maniobra', 'category' => 'controllers', 'price_mode' => 'fixed', 'price' => '98000.50', 'availability' => 'to_order',
            // Intentos de manipular dueño, estado, moderación y destacado: se ignoran.
            'supplier_id' => $other->id, 'status' => 'published', 'hidden_by_admin' => 0, 'featured_until' => '2099-01-01',
        ])->assertRedirect();

        $product = SupplierProduct::where('name', 'Placa de maniobra')->sole();
        $this->assertSame([$supplier->id, SupplierProduct::DRAFT, null], [$product->supplier_id, $product->status, $product->featured_until]);

        // Precio «a cotizar»: el precio se borra. Fijo sin precio: error.
        $this->put(route('suppliers.products.update', $product->id), ['name' => 'Placa', 'category' => 'controllers', 'price_mode' => 'quote', 'price' => '5', 'availability' => 'to_order'])->assertRedirect();
        $this->assertNull($product->fresh()->price);
        $this->put(route('suppliers.products.update', $product->id), ['name' => 'Placa', 'category' => 'controllers', 'price_mode' => 'fixed', 'availability' => 'to_order'])->assertSessionHasErrors('price');

        // Publicar → pausar → retirar; saltos inválidos no.
        $this->post(route('suppliers.products.status', $product->id), ['estado' => 'paused'])->assertSessionHasErrors('estado');
        $this->post(route('suppliers.products.status', $product->id), ['estado' => 'published'])->assertSessionHas('status', 'Publicado. Se va a ver cuando aprobemos tu cuenta.');
        $this->post(route('suppliers.products.status', $product->id), ['estado' => 'paused']);
        $this->post(route('suppliers.products.status', $product->id), ['estado' => 'retired']);
        $this->assertSame(SupplierProduct::RETIRED, $product->fresh()->status);

        // Lo de otro proveedor: 404, para todo.
        $this->get(route('suppliers.products.edit', $foreign->id))->assertNotFound();
        $this->put(route('suppliers.products.update', $foreign->id), ['name' => 'x', 'category' => 'doors', 'price_mode' => 'quote', 'availability' => 'to_order'])->assertNotFound();
        $this->post(route('suppliers.products.status', $foreign->id), ['estado' => 'paused'])->assertNotFound();
        $this->assertSame('Operador de puerta', $foreign->fresh()->name);
    }

    public function test_product_photos_are_validated_and_only_shown_to_who_may_see_them(): void
    {
        Storage::fake('local');
        [$supplier, $user] = $this->makeSupplier(Supplier::APPROVED);
        [, $otherUser] = $this->makeSupplier(Supplier::APPROVED, 'Otro');
        $product = $this->makeProduct($supplier);

        $this->actingAs($user)->post(route('suppliers.products.photos.store', $product->id), ['foto' => UploadedFile::fake()->create('virus.php', 10, 'application/x-php')])->assertSessionHasErrors('foto');
        $this->post(route('suppliers.products.photos.store', $product->id), ['foto' => UploadedFile::fake()->create('falsa.jpg', 10, 'image/jpeg')])->assertSessionHasErrors('foto');
        $this->post(route('suppliers.products.photos.store', $product->id), ['foto' => UploadedFile::fake()->image('ok.jpg', 800, 600)])->assertSessionHasNoErrors();
        $photo = $product->photos()->sole();
        $this->assertStringStartsWith('marketplace/'.$supplier->id.'/', $photo->path);
        $this->get(route('marketplace.photo', $photo->id))->assertOk();

        // Otro proveedor y un técnico: no. Un comprador: sí, mientras esté visible.
        $this->actingAs($otherUser)->get(route('marketplace.photo', $photo->id))->assertNotFound();
        $a = $this->makeTenantLoggedOut();
        $this->actingAs($a['technician'])->get(route('marketplace.photo', $photo->id))->assertNotFound();
        $this->actingAs($a['admin'])->get(route('marketplace.photo', $photo->id))->assertOk();
        $product->forceFill(['hidden_by_admin' => true])->save();
        $this->get(route('marketplace.photo', $photo->id))->assertNotFound();
    }

    public function test_a_suspended_supplier_can_look_but_not_change_anything(): void
    {
        [$supplier, $user] = $this->makeSupplier(Supplier::SUSPENDED);
        $product = $this->makeProduct($supplier);

        $this->actingAs($user)->get(route('suppliers.home'))->assertOk()->assertSee('Tu cuenta está suspendida');
        $this->post(route('suppliers.products.store'), ['name' => 'x', 'category' => 'doors', 'price_mode' => 'quote', 'availability' => 'to_order'])->assertForbidden();
        $this->post(route('suppliers.products.status', $product->id), ['estado' => 'paused'])->assertForbidden();
        $this->put(route('suppliers.profile.update'), ['name' => 'Nuevo'])->assertForbidden();
    }

    private function makeTenantLoggedOut(): array
    {
        auth()->logout();

        return $this->makeTenant();
    }
}
