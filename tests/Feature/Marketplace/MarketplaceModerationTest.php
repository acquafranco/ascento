<?php

namespace Tests\Feature\Marketplace;

use App\Filament\Pages\Marketplace\PartsCatalog;
use App\Filament\Resources\Marketplace\MarketplaceReportResource;
use App\Filament\Resources\Marketplace\Pages\ListMarketplaceReports;
use App\Filament\Resources\Marketplace\Pages\ListSupplierProducts;
use App\Filament\Resources\Marketplace\Pages\ListSuppliers;
use App\Filament\Resources\Marketplace\SupplierProductResource;
use App\Filament\Resources\Marketplace\SupplierResource;
use App\Models\Marketplace\MarketplaceReport;
use App\Models\Marketplace\Supplier;
use App\Models\PlatformAuditLog;
use App\Models\User;
use App\Notifications\Marketplace\MarketplaceAdminNotice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithTenants;
use Tests\Feature\Marketplace\Concerns\MakesMarketplace;
use Tests\TestCase;

/**
 * Moderación del marketplace: solo el SuperAdmin aprueba, suspende, oculta,
 * destaca y resuelve reportes; todo queda registrado. Un destacado nunca
 * saltea la moderación.
 */
class MarketplaceModerationTest extends TestCase
{
    use InteractsWithTenants, MakesMarketplace, RefreshDatabase;

    public function test_only_the_super_admin_sees_the_moderation_screens(): void
    {
        $a = $this->makeTenant();
        $this->actingInPanel($a['admin']);
        foreach ([SupplierResource::getUrl(), SupplierProductResource::getUrl(), MarketplaceReportResource::getUrl()] as $url) {
            $this->assertNotSame(200, $this->get($url)->status(), $url);
        }
    }

    public function test_approving_and_suspending_a_supplier_controls_its_catalog_and_is_logged(): void
    {
        [$supplier] = $this->makeSupplier(Supplier::PENDING);
        $product = $this->makeProduct($supplier, ['name' => 'Limitador de velocidad']);
        $super = User::factory()->superAdmin()->create();
        $this->actingInPanel($super);

        Livewire::test(ListSuppliers::class)->callTableAction('approve', $supplier)->assertNotified('Proveedor aprobado');
        $this->assertSame(Supplier::APPROVED, $supplier->fresh()->status);
        $this->assertTrue($product->fresh()->isVisible());

        Livewire::test(ListSuppliers::class)->callTableAction('suspend', $supplier, ['reason' => 'Datos fiscales inconsistentes'])->assertNotified('Proveedor suspendido');
        $supplier->refresh();
        $this->assertSame([Supplier::SUSPENDED, 'Datos fiscales inconsistentes'], [$supplier->status, $supplier->status_reason]);
        $this->assertFalse($product->fresh()->isVisible());

        $this->assertSame(['marketplace.supplier.approved', 'marketplace.supplier.suspended'], PlatformAuditLog::orderBy('id')->pluck('action')->all());
        $this->assertSame($super->id, PlatformAuditLog::first()->user_id);
    }

    public function test_hiding_and_featuring_products(): void
    {
        [$supplier] = $this->makeSupplier();
        $product = $this->makeProduct($supplier, ['name' => 'Zapata de guía']);
        $super = User::factory()->superAdmin()->create();
        $this->actingInPanel($super);

        Livewire::test(ListSupplierProducts::class)->callTableAction('feature', $product, ['until' => now()->addDays(10)->toDateString()])->assertNotified();
        $this->assertTrue($product->fresh()->isFeatured());
        Livewire::test(ListSupplierProducts::class)->callTableAction('hide', $product, ['reason' => 'Fotos de otra marca'])->assertNotified('Producto oculto');
        $product->refresh();
        $this->assertTrue($product->hidden_by_admin && $product->isFeatured());
        $this->assertFalse($product->isVisible()); // destacado y oculto: oculto

        // El proveedor no lo puede volver a mostrar desde su formulario.
        auth()->logout();
        $owner = $supplier->users()->first();
        $this->actingAs($owner)->put(route('suppliers.products.update', $product->id), ['name' => 'Zapata', 'category' => 'guides', 'price_mode' => 'quote', 'availability' => 'in_stock', 'hidden_by_admin' => 0]);
        $this->assertTrue($product->fresh()->hidden_by_admin);
        $this->get(route('suppliers.products.edit', $product->id))->assertSee('Oculto por moderación')->assertSee('Fotos de otra marca');
        $this->assertSame(2, PlatformAuditLog::count());
    }

    public function test_a_buyer_reports_a_product_once_and_the_super_admin_resolves_it(): void
    {
        $super = User::factory()->superAdmin()->create();
        [$supplier] = $this->makeSupplier();
        $product = $this->makeProduct($supplier);
        $a = $this->makeTenant();
        $this->actingInPanel($a['admin']);

        Livewire::test(PartsCatalog::class, ['producto' => $product->id])->call('startReport', $product->id)->call('submitReport')->assertHasErrors('reportReason')
            ->set('reportReason', 'fraud')->set('reportDetails', 'Pide pago por adelantado a otra cuenta')->call('submitReport')->assertNotified('Gracias, lo vamos a revisar');
        Livewire::test(PartsCatalog::class, ['producto' => $product->id])->call('startReport', $product->id)->set('reportReason', 'other')->call('submitReport');
        $report = MarketplaceReport::sole();
        $this->assertSame([$a['company']->id, 'fraud'], [$report->company_id, $report->reason]);
        $this->assertSame(1, $super->notifications()->where('type', MarketplaceAdminNotice::class)->count());

        auth()->logout();
        $this->actingInPanel($super);
        Livewire::test(ListMarketplaceReports::class)->callTableAction('resolve', $report, ['resolution' => 'Publicación engañosa', 'hide' => true])->assertNotified('Reporte resuelto');
        $this->assertSame('resolved', $report->fresh()->status);
        $this->assertTrue($product->fresh()->hidden_by_admin);
        $this->assertSame(['marketplace.product.hidden', 'marketplace.report.resolved'], PlatformAuditLog::orderBy('id')->pluck('action')->all());
    }
}
