<?php

namespace Tests\Feature\Marketplace;

use App\Filament\Pages\Marketplace\PartsCatalog;
use App\Models\Marketplace\Supplier;
use App\Models\Marketplace\SupplierProduct;
use App\Models\Marketplace\SupplierProductPhoto;
use App\Support\Marketplace\Catalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithTenants;
use Tests\Feature\Marketplace\Concerns\MakesMarketplace;
use Tests\TestCase;

/**
 * Varias fotos por producto: subir varias a la vez, elegir la principal,
 * reordenar y quitar, siempre dentro del producto propio; archivos inválidos
 * o de más no dejan nada a medias; la empresa ve la principal en la tarjeta y
 * la galería en la ficha.
 */
class ProductPhotosTest extends TestCase
{
    use InteractsWithTenants, MakesMarketplace, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function img(string $name = 'foto.jpg'): UploadedFile
    {
        return UploadedFile::fake()->image($name, 900, 700);
    }

    private function makeTenantLoggedOut(): array
    {
        auth()->logout();

        return $this->makeTenant();
    }

    /** @return list<int> ids en el orden en que se muestran */
    private function order(SupplierProduct $product): array
    {
        return $product->photos()->pluck('id')->all();
    }

    private function files(): array
    {
        return Storage::disk('local')->allFiles('marketplace');
    }

    public function test_several_photos_upload_at_once_in_order_and_are_re_encoded(): void
    {
        [$supplier, $user] = $this->makeSupplier();
        $product = $this->makeProduct($supplier);

        $this->actingAs($user)->post(route('suppliers.products.photos.store', $product->id), ['fotos' => [$this->img('a.png'), $this->img('b.jpg'), $this->img('c.webp')]])
            ->assertSessionHasNoErrors()->assertSessionHas('status', '3 fotos agregadas.');

        $photos = $product->photos()->get();
        $this->assertSame([1, 2, 3], $photos->pluck('position')->all());
        foreach ($photos as $photo) {
            // Nombre propio del servidor (nunca el del navegador), siempre JPEG, con miniatura.
            $this->assertMatchesRegularExpression('#^marketplace/'.$supplier->id.'/'.$product->id.'/[A-Za-z0-9]{40}\.jpg$#', $photo->path);
            Storage::disk('local')->assertExists([$photo->path, $photo->thumb_path]);
            $this->assertSame('image/jpeg', Storage::disk('local')->mimeType($photo->path));
        }
        $this->get(route('suppliers.products.edit', $product->id))->assertOk()->assertSee('3 de '.Catalog::MAX_PHOTOS)->assertSee('Principal')
            ->assertSee('Hacer principal')->assertSee('name="fotos[]"', false)->assertSee('multiple', false);
    }

    public function test_too_many_or_invalid_files_leave_nothing_half_done(): void
    {
        [$supplier, $user] = $this->makeSupplier();
        $product = $this->makeProduct($supplier);
        $this->actingAs($user);

        // Archivos inválidos con lugar de sobra: uno bueno y uno que no es imagen (aunque diga .jpg) → no se guarda ninguno.
        $this->post(route('suppliers.products.photos.store', $product->id), ['fotos' => [$this->img(), UploadedFile::fake()->create('falsa.jpg', 10, 'image/jpeg')]])->assertSessionHasErrors('fotos');
        $this->post(route('suppliers.products.photos.store', $product->id), ['fotos' => [UploadedFile::fake()->create('script.php', 5, 'application/x-php')]])->assertSessionHasErrors('fotos.0');
        $this->post(route('suppliers.products.photos.store', $product->id), ['fotos' => [UploadedFile::fake()->image('enorme.jpg', 900, 700)->size(Catalog::MAX_PHOTO_KB + 1)]])
            ->assertSessionHasErrors(['fotos.0' => 'Una de las fotos pesa más de '.(Catalog::MAX_PHOTO_KB / 1024).' MB.']);
        $this->post(route('suppliers.products.photos.store', $product->id), [])->assertSessionHasErrors(['fotos' => 'Elegí al menos una foto.']);
        // Más archivos que el máximo de una vez.
        $this->post(route('suppliers.products.photos.store', $product->id), ['fotos' => array_map(fn ($i) => $this->img(), range(1, Catalog::MAX_PHOTOS + 1))])->assertSessionHasErrors('fotos');
        $this->assertSame(0, $product->photos()->count());
        $this->assertSame([], $this->files());

        $this->post(route('suppliers.products.photos.store', $product->id), ['fotos' => array_map(fn ($i) => $this->img("f$i.jpg"), range(1, Catalog::MAX_PHOTOS - 1))]);
        $before = $this->files();
        $this->assertCount((Catalog::MAX_PHOTOS - 1) * 2, $before);

        // Dos más cuando queda lugar para una: mensaje claro y nada nuevo.
        $this->post(route('suppliers.products.photos.store', $product->id), ['fotos' => [$this->img(), $this->img()]])
            ->assertSessionHasErrors(['fotos' => 'Este producto ya tiene '.(Catalog::MAX_PHOTOS - 1).' fotos: podés agregar 1 más (máximo '.Catalog::MAX_PHOTOS.').']);

        $this->assertSame(Catalog::MAX_PHOTOS - 1, $product->photos()->count());
        $this->assertSame($before, $this->files());

        // La que entra, entra; y con el máximo completo ya no se ofrece subir.
        $this->post(route('suppliers.products.photos.store', $product->id), ['fotos' => [$this->img()]])->assertSessionHasNoErrors();
        $this->get(route('suppliers.products.edit', $product->id))->assertDontSee('name="fotos[]"', false)->assertSee('Llegaste al máximo');
    }

    public function test_a_photo_that_can_not_be_decoded_is_rejected_without_leftovers(): void
    {
        [$supplier, $user] = $this->makeSupplier();
        $product = $this->makeProduct($supplier);
        // Pasa el control de tipo del navegador (es un PNG de cabecera) pero está corrupta.
        $broken = UploadedFile::fake()->createWithContent('rota.png', "\x89PNG\r\n\x1a\n".str_repeat("\0", 64));

        $this->actingAs($user)->post(route('suppliers.products.photos.store', $product->id), ['fotos' => [$this->img(), $broken]])->assertSessionHasErrors();
        $this->assertSame(0, $product->photos()->count());
        $this->assertSame([], $this->files());
    }

    public function test_the_supplier_chooses_the_main_photo_reorders_and_removes(): void
    {
        [$supplier, $user] = $this->makeSupplier();
        $product = $this->makeProduct($supplier);
        $this->actingAs($user)->post(route('suppliers.products.photos.store', $product->id), ['fotos' => [$this->img(), $this->img(), $this->img(), $this->img()]]);
        [$a, $b, $c, $d] = $this->order($product);

        $this->post(route('suppliers.products.photos.primary', [$product->id, $c]))->assertSessionHas('status', 'Foto principal actualizada.');
        $this->assertSame([$c, $a, $b, $d], $this->order($product));
        $this->post(route('suppliers.products.photos.move', [$product->id, $d]), ['direccion' => 'antes']);
        $this->assertSame([$c, $a, $d, $b], $this->order($product));
        $this->post(route('suppliers.products.photos.move', [$product->id, $c]), ['direccion' => 'antes']); // ya es la primera: no cambia
        $this->post(route('suppliers.products.photos.move', [$product->id, $b]), ['direccion' => 'despues']); // ya es la última
        $this->assertSame([$c, $a, $d, $b], $this->order($product));
        $this->post(route('suppliers.products.photos.move', [$product->id, $a]), ['direccion' => 'arriba'])->assertSessionHasErrors('direccion');

        // Quitar la principal: la siguiente pasa a serlo, y sus archivos se borran.
        $files = SupplierProductPhoto::whereKey($c)->first(['path', 'thumb_path']);
        $this->delete(route('suppliers.products.photos.destroy', [$product->id, $c]))->assertSessionHas('status', 'Foto eliminada.');
        $this->assertSame([$a, $d, $b], $this->order($product));
        $this->assertSame([1, 2, 3], $product->photos()->pluck('position')->all());
        Storage::disk('local')->assertMissing([$files->path, $files->thumb_path]);
        Storage::disk('local')->assertExists(SupplierProductPhoto::whereKey($a)->value('path'));
    }

    public function test_photos_loaded_before_keep_their_order_and_can_be_reordered(): void
    {
        [$supplier, $user] = $this->makeSupplier();
        $product = $this->makeProduct($supplier);
        // Fotos de antes: todas con la misma posición (se muestran por orden de carga).
        foreach (['x', 'y', 'z'] as $n) {
            Storage::disk('local')->put("marketplace/{$supplier->id}/{$product->id}/{$n}.jpg", 'jpg');
            $product->photos()->create(['supplier_id' => $supplier->id, 'path' => "marketplace/{$supplier->id}/{$product->id}/{$n}.jpg", 'thumb_path' => "marketplace/{$supplier->id}/{$product->id}/{$n}_thumb.jpg", 'position' => 1]);
        }
        [$x, $y, $z] = $this->order($product);

        $this->actingAs($user)->post(route('suppliers.products.photos.primary', [$product->id, $z]));
        $this->assertSame([$z, $x, $y], $this->order($product));
        $this->assertSame([1, 2, 3], $product->photos()->pluck('position')->all());
        $this->assertSame(3, $product->photos()->count());
    }

    public function test_nobody_else_can_touch_a_suppliers_photos(): void
    {
        [$supplier, $user] = $this->makeSupplier();
        [$other, $otherUser] = $this->makeSupplier(Supplier::APPROVED, 'Competidor');
        $product = $this->makeProduct($supplier);
        $sibling = $this->makeProduct($supplier, ['name' => 'Otro producto']);
        $foreign = $this->makeProduct($other, ['name' => 'Ajeno']);
        $this->actingAs($user)->post(route('suppliers.products.photos.store', $product->id), ['fotos' => [$this->img(), $this->img()]]);
        [$p1, $p2] = $this->order($product);
        $before = $this->files();

        // Otro proveedor, con los ids reales: 404 en todo.
        $this->actingAs($otherUser);
        $this->post(route('suppliers.products.photos.store', $product->id), ['fotos' => [$this->img()]])->assertNotFound();
        $this->post(route('suppliers.products.photos.primary', [$product->id, $p2]))->assertNotFound();
        $this->post(route('suppliers.products.photos.move', [$product->id, $p2]), ['direccion' => 'antes'])->assertNotFound();
        $this->delete(route('suppliers.products.photos.destroy', [$product->id, $p1]))->assertNotFound();
        // Su propio producto con una foto ajena en la URL: tampoco.
        $this->delete(route('suppliers.products.photos.destroy', [$foreign->id, $p1]))->assertNotFound();

        // El mismo proveedor, pero la foto de otro de sus productos: 404 (no se mezclan).
        $this->actingAs($user);
        $this->delete(route('suppliers.products.photos.destroy', [$sibling->id, $p1]))->assertNotFound();
        $this->post(route('suppliers.products.photos.primary', [$sibling->id, $p2]))->assertNotFound();

        // Un proveedor suspendido no modifica.
        $supplier->forceFill(['status' => Supplier::SUSPENDED])->save();
        $this->actingAs($user->fresh()); // cada pedido real vuelve a leer la cuenta
        $this->delete(route('suppliers.products.photos.destroy', [$product->id, $p1]))->assertForbidden();
        $this->post(route('suppliers.products.photos.primary', [$product->id, $p2]))->assertForbidden();

        // Una empresa no entra al área de proveedores.
        $t = $this->makeTenantLoggedOut();
        $this->actingAs($t['admin'])->post(route('suppliers.products.photos.store', $product->id), ['fotos' => [$this->img()]])->assertStatus(302);
        $this->delete(route('suppliers.products.photos.destroy', [$product->id, $p1]))->assertStatus(302);

        $this->assertSame([$p1, $p2], $this->order($product));
        $this->assertSame($before, $this->files());
    }

    public function test_the_company_sees_the_main_photo_in_the_card_and_a_gallery_in_the_detail(): void
    {
        [$supplier, $user] = $this->makeSupplier();
        $many = $this->makeProduct($supplier, ['name' => 'Operador con galería']);
        $one = $this->makeProduct($supplier, ['name' => 'Con una sola foto']);
        $none = $this->makeProduct($supplier, ['name' => 'Sin ninguna foto']);
        $this->actingAs($user)->post(route('suppliers.products.photos.store', $many->id), ['fotos' => [$this->img(), $this->img(), $this->img()]]);
        $this->post(route('suppliers.products.photos.store', $one->id), ['fotos' => [$this->img()]]);
        [$a, $b, $c] = $this->order($many);
        $this->post(route('suppliers.products.photos.primary', [$many->id, $c]));

        $t = $this->makeTenantLoggedOut();
        $this->actingInPanel($t['admin']);
        $list = Livewire::test(PartsCatalog::class)->assertOk();
        // La tarjeta muestra la principal (miniatura) y cuántas fotos hay.
        $list->assertSeeHtml(route('marketplace.photo', ['photo' => $c, 'thumb' => 1]))->assertDontSeeHtml(route('marketplace.photo', ['photo' => $a, 'thumb' => 1]))
            ->assertSee('3 fotos')->assertSee('Sin foto')->assertSee('Ver detalle');

        $detail = Livewire::withQueryParams(['producto' => $many->id])->test(PartsCatalog::class)->assertOk()
            ->assertSeeHtml('data-mk-gallery')->assertSeeHtml('data-mk-prev')->assertSeeHtml('data-mk-next')->assertSee('Ver foto 3')
            ->assertSeeInOrder([route('marketplace.photo', ['photo' => $c]), route('marketplace.photo', ['photo' => $a]), route('marketplace.photo', ['photo' => $b])]);
        $this->assertSame(3, substr_count($detail->html(), 'class="mk-slide"'));

        // Una sola foto: sin flechas ni miniaturas. Ninguna: aviso, sin imagen rota.
        Livewire::withQueryParams(['producto' => $one->id])->test(PartsCatalog::class)->assertSeeHtml('data-mk-gallery')->assertDontSeeHtml('data-mk-prev')->assertDontSee('Ver foto 1');
        Livewire::withQueryParams(['producto' => $none->id])->test(PartsCatalog::class)->assertDontSeeHtml('data-mk-gallery')->assertSee('El proveedor todavía no cargó fotos');

        // Las fotos se sirven a la empresa mientras el producto esté visible.
        $this->get(route('marketplace.photo', ['photo' => $c, 'thumb' => 1]))->assertOk()->assertHeader('Content-Type', 'image/jpeg');
        $many->forceFill(['status' => SupplierProduct::PAUSED])->save();
        $this->get(route('marketplace.photo', $c))->assertNotFound();
    }
}
