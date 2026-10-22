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
 * cambia nada, los proveedores aprobados empiezan su prueba al aplicarla, los
 * pendientes no, y el historial deja de borrarse en cascada.
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

        $this->assertNotNull(DB::table('suppliers')->where('id', $approved->id)->value('trial_ends_at'));
        $this->assertNull(DB::table('suppliers')->where('id', $pending->id)->value('trial_ends_at'));
        $this->assertSame('ARS', DB::table('suppliers')->where('id', $approved->id)->value('currency'));
        $this->assertSame('CLP', DB::table('suppliers')->where('id', $pending->id)->value('currency'));
        $this->assertSame($a['company']->name, DB::table('marketplace_requests')->where('id', $requestId)->value('buyer_name'));
        $this->assertEquals((array) $before, (array) DB::table('supplier_products')->where('id', $productId)->first());
        $this->assertSame(1, DB::table('marketplace_requests')->count());

        // Borrar la empresa ya no borra el pedido del proveedor; el proveedor no se borra en cascada.
        $fks = collect(Schema::getForeignKeys('marketplace_requests'))->mapWithKeys(fn ($fk) => [$fk['columns'][0] => strtolower((string) $fk['on_delete'])]);
        $this->assertSame('set null', $fks['company_id']);
        $this->assertSame('restrict', $fks['supplier_id']);
        $this->expectException(QueryException::class);
        DB::table('suppliers')->where('id', $approved->id)->delete();
    }
}
