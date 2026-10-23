<?php

namespace Tests\Feature\Marketplace;

use App\Filament\Pages\Marketplace\PartsCatalog;
use App\Models\User;
use App\Notifications\Marketplace\MarketplaceNotice;
use App\Services\Marketplace\FeaturedPlacements;
use App\Services\Marketplace\MarketplaceNotifier;
use App\Services\Marketplace\MarketplaceRequests;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Livewire\Livewire;
use RuntimeException;
use Tests\Concerns\InteractsWithTenants;
use Tests\Feature\Marketplace\Concerns\MakesMarketplace;
use Tests\TestCase;

/**
 * 2027_01_21: borra solo las estructuras y los datos de «Profesionales y
 * servicios» (función retirada). Nada comercial de repuestos cambia, no corre
 * si hay cuentas «solo servicios» y se puede ir y volver.
 */
class ServicesCleanupMigrationTest extends TestCase
{
    use InteractsWithTenants, MakesMarketplace, RefreshDatabase;

    private const MIGRATION = 'database/migrations/2027_01_21_100000_drop_service_profiles_tables.php';

    /** Tablas de repuestos y cuentas que la limpieza nunca debe cambiar. */
    private const KEPT = ['suppliers', 'supplier_products', 'supplier_product_photos', 'marketplace_requests', 'marketplace_request_items', 'marketplace_request_events',
        'marketplace_favorites', 'marketplace_reports', 'marketplace_settings', 'featured_placements', 'supplier_payments', 'supplier_subscriptions', 'companies', 'users'];

    private function snapshot(): array
    {
        return collect(self::KEPT)->mapWithKeys(fn ($t) => [$t => DB::table($t)->orderBy('id')->get()
            ->map(fn ($row) => collect((array) $row)->except('sells_products')->all())->all()])->all();
    }

    private function serviceData(int $supplierId, int $companyId, int $userId): void
    {
        $profile = DB::table('service_profiles')->insertGetId(['supplier_id' => $supplierId, 'slug' => 'placas-sur', 'kind' => 'independent', 'modality' => 'onsite',
            'public_name' => 'Placas Sur', 'description' => 'Placas', 'status' => 'published', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('service_profile_category')->insert(['service_profile_id' => $profile, 'service_category_id' => DB::table('service_categories')->value('id')]);
        DB::table('service_profile_terms')->insert(['service_profile_id' => $profile, 'type' => 'brand', 'term' => 'otis', 'label' => 'Otis']);
        $request = DB::table('service_contact_requests')->insertGetId(['reference' => 'P-ABCDEFGH', 'service_profile_id' => $profile, 'company_id' => $companyId, 'created_by' => $userId,
            'profile_name' => 'Placas Sur', 'contact_name' => 'Ana', 'contact_email' => 'ana@e.test', 'subject' => 'Placa', 'need' => 'Datos', 'status' => 'open',
            'idempotency_key' => str_repeat('b', 64), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('service_contact_messages')->insert(['service_contact_request_id' => $request, 'side' => 'company', 'user_id' => $userId, 'body' => 'Hola', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('service_profile_reports')->insert(['service_profile_id' => $profile, 'company_id' => $companyId, 'user_id' => $userId, 'reason' => 'spam', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function notice(User $user, string $key): void
    {
        DB::table('notifications')->insert(['id' => (string) Str::uuid(), 'type' => MarketplaceNotice::class, 'notifiable_type' => $user->getMorphClass(), 'notifiable_id' => $user->id,
            'data' => json_encode(['title' => $key]), 'dedupe_key' => $key, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_it_removes_only_the_retired_feature_and_keeps_every_commercial_row(): void
    {
        $migration = require base_path(self::MIGRATION);
        $migration->down(); // estado de producción hoy: estructuras de 2027_01_20 presentes
        $this->assertTrue(Schema::hasTable('service_profiles'));
        $this->assertSame(11, DB::table('service_categories')->count());

        $super = User::factory()->superAdmin()->create();
        $t = $this->makeTenant();
        [$supplier, $supplierUser] = $this->makeSupplier();
        $product = $this->makeProduct($supplier);
        app(MarketplaceRequests::class)->create($t['admin'], $supplier->id, [$product->id => 1], ['contact_name' => 'Ana', 'contact_email' => 'ana@e.test'], 'pedido');
        $placement = app(FeaturedPlacements::class)->grant($super, $product, now()->addDays(7));
        $subscription = DB::table('supplier_subscriptions')->insertGetId(['supplier_id' => $supplier->id, 'external_reference' => 'sub-1', 'status' => 'authorized', 'amount' => 19900, 'currency' => 'ARS',
            'current_period_end' => now()->addMonth(), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('supplier_payments')->insert(['supplier_id' => $supplier->id, 'kind' => 'featured', 'featured_placement_id' => $placement->id, 'provider_payment_id' => 'pay-1', 'status' => 'approved',
            'amount' => 4900, 'currency' => 'ARS', 'paid_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('supplier_payments')->insert(['supplier_id' => $supplier->id, 'kind' => 'subscription', 'supplier_subscription_id' => $subscription, 'provider_payment_id' => 'pay-2', 'status' => 'refunded',
            'amount' => 19900, 'currency' => 'ARS', 'paid_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        app(MarketplaceNotifier::class)->supplier($supplier, 'Pedido nuevo', 'Repuestos', '/proveedores/pedidos', 'request:1', mail: false, push: false);
        app(MarketplaceNotifier::class)->team('Proveedor nuevo para revisar', 'Repuestos', '/admin/marketplace/proveedores/1', 'supplier-new:1');
        DB::table('platform_audit_logs')->insert(['actor_name' => 'Super', 'action' => 'marketplace.settings.updated', 'created_at' => now()]);

        // Restos de la función retirada.
        $this->serviceData($supplier->id, $t['company']->id, $t['admin']->id);
        foreach (['mkt:team:svc-profile-review:1', 'mkt:team:svc-report:1', 'mkt:s'.$supplier->id.':svc:new:1', 'mkt:c'.$t['company']->id.':svc:new:1', 'mkt:sr1:answered'] as $key) {
            $this->notice($key === 'mkt:sr1:answered' ? $t['admin'] : ($key[4] === 't' ? $super : $supplierUser), $key);
        }
        foreach (['marketplace.service_profile.approved', 'marketplace.service_request.viewed', 'marketplace.service_report.resolved', 'marketplace.service_category.saved'] as $action) {
            DB::table('platform_audit_logs')->insert(['actor_name' => 'Super', 'action' => $action, 'created_at' => now()]);
        }
        $commercialNotices = DB::table('notifications')->where('dedupe_key', 'not like', '%svc%')->where('dedupe_key', 'not like', 'mkt:sr%')->orderBy('id')->pluck('dedupe_key')->all();
        $this->assertGreaterThanOrEqual(2, count($commercialNotices)); // pedido, destacado, alta y avisos de arriba
        $commercialAudit = DB::table('platform_audit_logs')->where('action', 'not like', 'marketplace.service%')->orderBy('id')->pluck('action')->all();
        $this->assertContains('marketplace.settings.updated', $commercialAudit);
        $before = $this->snapshot();

        $migration->up();

        foreach ($migration::TABLES as $table) {
            $this->assertFalse(Schema::hasTable($table), $table);
        }
        $this->assertFalse(Schema::hasColumn('suppliers', 'sells_products'));
        $this->assertSame($before, $this->snapshot());
        $this->assertSame($commercialNotices, DB::table('notifications')->orderBy('id')->pluck('dedupe_key')->all());
        $this->assertSame($commercialAudit, DB::table('platform_audit_logs')->orderBy('id')->pluck('action')->all());

        // Después de la limpieza, el catálogo y los pedidos siguen funcionando.
        $this->actingInPanel($t['admin']);
        Livewire::test(PartsCatalog::class)->assertOk()->assertSee($product->name);
        $order = app(MarketplaceRequests::class)->create($t['admin'], $supplier->id, [$product->id => 3], ['contact_name' => 'Ana', 'contact_email' => 'ana@e.test'], 'pedido-despues');
        $this->assertSame(3, (int) $order->items()->value('quantity'));
        $this->actingAs($supplierUser)->get(route('suppliers.requests.show', $order->reference))->assertOk()->assertSee($product->name);
        $this->get(route('suppliers.products.index'))->assertOk()->assertSee($product->name);
        $before = $this->snapshot();

        // Ida y vuelta: down recrea la estructura vacía y up vuelve a limpiar.
        $migration->down();
        $this->assertTrue(Schema::hasColumn('suppliers', 'sells_products'));
        $this->assertSame(0, DB::table('service_profiles')->count());
        $migration->up();
        $this->assertFalse(Schema::hasTable('service_profiles'));
        $this->assertSame($before, $this->snapshot());
    }

    public function test_it_refuses_to_run_while_a_services_only_account_exists(): void
    {
        $migration = require base_path(self::MIGRATION);
        $migration->down();
        [$supplier] = $this->makeSupplier();
        $t = $this->makeTenant();
        DB::table('suppliers')->where('id', $supplier->id)->update(['sells_products' => false, 'tax_id' => null]);
        $this->serviceData($supplier->id, $t['company']->id, $t['admin']->id);

        try {
            $migration->up();
            $this->fail('Debía detenerse.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('ids: '.$supplier->id, $e->getMessage());
        }
        // No borró nada.
        $this->assertTrue(Schema::hasColumn('suppliers', 'sells_products'));
        $this->assertSame(1, DB::table('service_profiles')->count());
        $this->assertSame(1, DB::table('service_contact_requests')->count());
    }

    public function test_a_previous_check_stops_the_deploy_before_anything_is_deleted(): void
    {
        $check = require base_path('database/migrations/2027_01_20_200000_check_service_cleanup_dependencies.php');
        $this->assertLessThan(0, strcmp('2027_01_20_200000_check_service_cleanup_dependencies', '2027_01_21_100000_drop_service_profiles_tables')); // corre antes
        $check->up(); // limpieza ya aplicada: no hace nada

        $migration = require base_path(self::MIGRATION);
        $migration->down();
        $check->up(); // estructuras presentes y sin dependencias: deja seguir

        // Una tabla ajena que apunta a una tabla de la función retirada: se detiene antes de borrar.
        Schema::create('tabla_ajena', function ($table) {
            $table->id();
            $table->foreignId('service_profile_id')->constrained();
        });
        try {
            $check->up();
            $this->fail('Debía detenerse.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('tabla_ajena.service_profile_id → service_profiles', $e->getMessage());
            $this->assertStringContainsString('no se borró nada', $e->getMessage());
        }
        Schema::drop('tabla_ajena');

        // Una cuenta «solo servicios»: también.
        [$supplier] = $this->makeSupplier();
        DB::table('suppliers')->where('id', $supplier->id)->update(['sells_products' => false]);
        $this->expectExceptionMessage('proveedores «solo servicios» (ids: '.$supplier->id.')');
        try {
            $check->up();
        } finally {
            $this->assertTrue(Schema::hasTable('service_profiles'));
            $this->assertSame(11, DB::table('service_categories')->count());
        }
    }
}
