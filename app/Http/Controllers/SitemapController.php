<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Marketplace\PublicCatalogController;
use App\Models\Marketplace\MarketplaceSettings;
use App\Models\Marketplace\Supplier;
use App\Models\Marketplace\SupplierProduct;
use Illuminate\Http\Response;

/**
 * Mapa del sitio (/sitemap.xml) para buscadores. Solo páginas públicas: la
 * página de inicio, el marketplace, las páginas legales y, con el catálogo
 * público encendido, los productos VISIBLES y los proveedores que tienen
 * alguno. Nunca paneles, áreas privadas, QR de edificios ni remitos (tienen
 * tokens propios y no se listan).
 */
class SitemapController extends Controller
{
    /** Tope de URLs por archivo que aceptan los buscadores. */
    private const MAX_URLS = 50000;

    public function __invoke(): Response
    {
        $urls = [
            ['loc' => url('/'), 'lastmod' => null],
            ['loc' => route('marketplace'), 'lastmod' => null],
            ['loc' => route('register'), 'lastmod' => null],
            ['loc' => route('suppliers.register'), 'lastmod' => null],
            ['loc' => route('legal.terms'), 'lastmod' => null],
            ['loc' => route('legal.privacy'), 'lastmod' => null],
            ['loc' => route('legal.cookies'), 'lastmod' => null],
            ['loc' => route('legal.data-deletion'), 'lastmod' => null],
        ];

        if (MarketplaceSettings::current()->publicCatalog()) {
            $urls[] = ['loc' => route('marketplace.catalog'), 'lastmod' => null];
            $products = SupplierProduct::visible()->reorder()->orderBy('supplier_products.id')
                ->limit(self::MAX_URLS - 100)->get(['supplier_products.id', 'supplier_products.name', 'supplier_products.updated_at', 'supplier_products.supplier_id']);
            foreach ($products as $product) {
                $urls[] = ['loc' => PublicCatalogController::productUrl($product), 'lastmod' => $product->updated_at];
            }
            $supplierIds = $products->pluck('supplier_id')->unique()->all();
            foreach (Supplier::operating()->whereIn('id', $supplierIds ?: [0])->orderBy('id')->get(['id', 'slug', 'updated_at']) as $supplier) {
                $urls[] = ['loc' => route('marketplace.supplier', $supplier->slug), 'lastmod' => $supplier->updated_at];
            }
        }

        return response()->view('sitemap', ['urls' => $urls])
            ->header('Content-Type', 'application/xml; charset=UTF-8')
            ->header('Cache-Control', 'public, max-age=3600');
    }
}
