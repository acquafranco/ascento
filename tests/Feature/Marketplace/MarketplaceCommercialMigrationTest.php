<?php

namespace Tests\Feature\Marketplace;

use App\Models\Marketplace\Supplier;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\InteractsWithTenants;
use Tests\Feature\Marketplace\Concerns\MakesMarketplace;
use Tests\TestCase;

/**
 * La migración del modelo comercial sobre datos que ya existían: no borra ni
 * cambia productos ni pedidos, todos los proveedores quedan en el plan
 * gratuito (sin prueba) y el historial deja de borrarse en cascada.
 */
class MarketplaceCommercialMigrationTest extends TestCase
{
    use InteractsWithTenants, MakesMarketplace, RefreshDatabase;

    private const MIGRATION = 'database/migrations/2027_01_18_100000_marketplace_commercial_model.php';

    public function test_it_applies_over_existing_rows_without_changing_them_and_rolls_back(): void
    {
        $migration = require base_path(self::MIGRATION);
        $migration->down();
        $this->assertFalse(Schema::hasTable('marketplace_settings'));

        // Datos «de antes»: un proveedor aprobado, uno pendiente y un pedido.
        [$approved] = $this->makeSupplier();
        [$pending] = $this->makeSupplier(Supplier::PENDING, 'Pendiente');
        DB::table('suppliers')->where('id', $pending->id)->update(['country' => 'CL']);
        $a = $this->makeTenant();
        $productId = DB::table('supplier_products')->insertGetId(['supplier_id' => $approved->id, 'name' => 'Polea', 'category' => 'ropes', 'status' => 'published', 'price_mode' => 'quote', 'created_at' => now(), 'updated_at' => now()]);
        $requestId = DB::table('marketplace_requests')->insertGetId(['reference' => 'R-LEGACY01', 'company_id' => $a['company']->id, 'supplier_id' => $approved->id, 'contact_name' => 'Ana', 'contact_email' => 'ana@e.test',
            'status' => 'sent', 'idempotency_key' => str_repeat('a', 64), 'created_at' => now(), 'updated_at' => now()]);
        $before = DB::table('supplier_products')->where('id', $productId)->first();

        $migration->up();

        // Configuración inicial: 20 / 1.000, Premium sin precio y destacados preparados pero apagados.
        $settings = DB::table('marketplace_settings')->sole();
        $this->assertSame([20, 1000, 0, null, 0], [(int) $settings->free_product_limit, (int) $settings->premium_product_limit, (int) $settings->premium_enabled, $settings->premium_price, (int) $settings->featured_enabled]);
        $this->assertFalse(Schema::hasColumn('suppliers', 'trial_ends_at'));
        $this->assertSame('ARS', DB::table('suppliers')->where('id', $approved->id)->value('currency'));
        $this->assertSame('CLP', DB::table('suppliers')->where('id', $pending->id)->value('currency'));
        $this->assertSame($a['company']->name, DB::table('marketplace_requests')->where('id', $requestId)->value('buyer_name'));
        $this->assertEquals((array) $before, array_intersect_key((array) DB::table('supplier_products')->where('id', $productId)->first(), (array) $before));
        $this->assertSame(1, DB::table('marketplace_requests')->count());

        // Borrar la empresa ya no borra el pedido del proveedor; el proveedor no se borra en cascada.
        $fks = collect(Schema::getForeignKeys('marketplace_requests'))->mapWithKeys(fn ($fk) => [$fk['columns'][0] => strtolower((string) $fk['on_delete'])]);
        $this->assertSame('set null', $fks['company_id']);
        $this->assertSame('restrict', $fks['supplier_id']);
        $this->expectException(QueryException::class);
        DB::table('suppliers')->where('id', $approved->id)->delete();
    }

    public function test_admin_featured_products_from_before_the_history_keep_their_label(): void
    {
        [$supplier] = $this->makeSupplier();
        $current = $this->makeProduct($supplier, ['name' => 'Vigente']);
        $expired = $this->makeProduct($supplier, ['name' => 'Vencido']);
        DB::table('supplier_products')->where('id', $current->id)->update(['featured_until' => now()->addDays(10)]);
        DB::table('supplier_products')->where('id', $expired->id)->update(['featured_until' => now()->subDay()]);
        $this->assertFalse($current->fresh()->isFeatured());

        $migration = require base_path('database/migrations/2027_01_19_100000_backfill_admin_featured_placements.php');
        $migration->up();
        $migration->up(); // se puede correr dos veces

        $this->assertSame(1, DB::table('featured_placements')->count());
        $this->assertTrue($current->fresh()->isFeatured());
        $this->assertFalse($expired->fresh()->isFeatured());
        $this->assertSame('admin', DB::table('featured_placements')->value('source'));
        $this->assertNull(DB::table('featured_placements')->value('amount'));

        $migration->down();
        $this->assertSame(0, DB::table('featured_placements')->count());
    }
}
