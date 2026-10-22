<?php

namespace Tests\Feature\Marketplace\Concerns;

use App\Models\Marketplace\Supplier;
use App\Models\Marketplace\SupplierProduct;
use App\Models\User;
use Illuminate\Support\Str;

trait MakesMarketplace
{
    /** @return array{0: Supplier, 1: User} */
    protected function makeSupplier(string $status = Supplier::APPROVED, string $name = 'Repuestos Sur'): array
    {
        $supplier = new Supplier(['name' => $name, 'contact_email' => Str::slug($name).'@proveedor.test', 'contact_phone' => '11 5555-0000', 'coverage' => 'CABA', 'categories' => ['doors'], 'brands' => ['Schindler']]);
        $supplier->forceFill(['slug' => Str::slug($name).'-'.Str::random(4), 'country' => 'AR', 'tax_id' => '30-'.random_int(10000000, 99999999).'-'.random_int(0, 9), 'status' => $status])->save();

        $user = User::factory()->create(['company_id' => null, 'email' => Str::slug($name).'-'.Str::random(4).'@proveedor.test']);
        $user->forceFill(['role' => User::ROLE_SUPPLIER, 'supplier_id' => $supplier->id, 'email_verified_at' => now()])->save();

        return [$supplier, $user->fresh()];
    }

    protected function makeProduct(Supplier $supplier, array $attributes = []): SupplierProduct
    {
        $product = new SupplierProduct(array_merge([
            'name' => 'Operador de puerta', 'category' => 'doors', 'brand' => 'Fermator', 'part_number' => 'VF5',
            'price_mode' => 'fixed', 'price' => 150000, 'availability' => 'in_stock', 'compatibility' => 'Puertas automáticas de 2 hojas',
        ], array_intersect_key($attributes, array_flip((new SupplierProduct)->getFillable()))));
        $product->forceFill(array_merge(['supplier_id' => $supplier->id, 'status' => SupplierProduct::PUBLISHED, 'published_at' => now()], array_diff_key($attributes, array_flip($product->getFillable()))))->save();

        return $product;
    }
}
