<?php

namespace App\Http\Controllers\Marketplace;

use App\Filament\Pages\Marketplace\PartsCatalog;
use App\Http\Controllers\Controller;
use App\Models\Marketplace\MarketplaceSettings;
use App\Models\Marketplace\Supplier;
use App\Models\Marketplace\SupplierProduct;
use App\Models\Marketplace\SupplierProductPhoto;
use App\Support\Marketplace\BuyerAccess;
use App\Support\Marketplace\Catalog;
use App\Support\Marketplace\CatalogRanking;
use App\Support\Marketplace\CatalogSearch;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Catálogo de repuestos sin cuenta (/marketplace/repuestos). Solo lectura y
 * solo productos visibles (SupplierProduct::visible: publicados, no ocultos
 * por moderación, de un proveedor habilitado y dentro del límite de su plan).
 *
 * Del proveedor se muestra únicamente su perfil comercial: nombre,
 * descripción, zonas, sitio web, rubros, marcas y operaciones completadas.
 * Nunca correos, teléfonos, identificación fiscal, pedidos ni compradores.
 * Pedir cotizaciones y favoritos siguen en el panel, con cuenta.
 *
 * El SuperAdmin puede apagarlo («Configuración del marketplace»): entonces
 * el listado vuelve a la página comercial y las fichas no existen (404).
 */
class PublicCatalogController extends Controller
{
    public const PER_PAGE = 24;

    private function ensureEnabled(): void
    {
        abort_unless(MarketplaceSettings::current()->publicCatalog(), 404);
    }

    public function index(Request $request): View|RedirectResponse
    {
        if (! MarketplaceSettings::current()->publicCatalog()) {
            return redirect()->route('marketplace');
        }

        $filters = [
            'rubro' => isset(Catalog::CATEGORIES[$request->query('rubro')]) ? (string) $request->query('rubro') : '',
            'marca' => mb_substr(trim((string) $request->query('marca')), 0, 120),
            'disponibilidad' => isset(Catalog::AVAILABILITY[$request->query('disponibilidad')]) ? (string) $request->query('disponibilidad') : '',
            'precio' => isset(Catalog::PRICE_MODES[$request->query('precio')]) ? (string) $request->query('precio') : '',
        ];
        $q = mb_substr(trim((string) $request->query('q')), 0, 120);
        $words = CatalogSearch::words($q);

        $query = CatalogRanking::apply(
            CatalogSearch::apply(SupplierProduct::visible()->with(['supplier:id,name,slug', 'photos']), $words, $filters),
            $words,
            0,
        );

        $products = $query->paginate(self::PER_PAGE)->withQueryString();
        $filtered = $q !== '' || array_filter($filters) !== [];

        return view('marketplace.public.catalog', [
            'products' => $products,
            'q' => $q,
            'filters' => $filters,
            'filtered' => $filtered,
            'brands' => SupplierProduct::visible()->whereNotNull('brand')->where('brand', '<>', '')->distinct()->orderBy('brand')->limit(150)->pluck('brand'),
            'categoryCounts' => self::categoryCounts(),
        ]);
    }

    public function product(Request $request, int $product, ?string $slug = null): View|RedirectResponse
    {
        $this->ensureEnabled();
        $item = SupplierProduct::visible()->with(['supplier', 'photos'])->findOrFail($product);

        // Una sola dirección por producto: el nombre puede cambiar, el id no.
        if ($slug !== self::slug($item)) {
            return redirect()->to(self::productUrl($item), 301);
        }

        $related = SupplierProduct::visible()->with(['supplier:id,name,slug', 'photos'])
            ->where('supplier_products.category', $item->category)->whereKeyNot($item->id)
            ->latest('published_at')->limit(4)->get();
        $fromSupplier = SupplierProduct::visible()->with(['supplier:id,name,slug', 'photos'])
            ->where('supplier_products.supplier_id', $item->supplier_id)->whereKeyNot($item->id)->whereNotIn('supplier_products.id', $related->pluck('id'))
            ->latest('published_at')->limit(4)->get();

        $user = $request->user();

        return view('marketplace.public.product', [
            'product' => $item,
            'supplier' => $item->supplier,
            'operations' => $item->supplier->completedOperations(),
            'related' => $related,
            'fromSupplier' => $fromSupplier,
            'panelUrl' => BuyerAccess::canBrowse($user) ? PartsCatalog::getUrl(['producto' => $item->id], panel: 'ascensores_app') : null,
            'isBuyer' => BuyerAccess::canBrowse($user),
            'isGuest' => $user === null,
        ]);
    }

    public function supplier(Request $request, string $supplier): View
    {
        $this->ensureEnabled();
        $profile = Supplier::operating()->where('slug', $supplier)->firstOrFail();

        $products = SupplierProduct::visible()->with(['supplier:id,name,slug', 'photos'])
            ->where('supplier_products.supplier_id', $profile->id)->latest('published_at')->orderByDesc('supplier_products.id')
            ->paginate(self::PER_PAGE)->withQueryString();

        return view('marketplace.public.supplier', [
            'supplier' => $profile,
            'products' => $products,
            'operations' => $profile->completedOperations(),
        ]);
    }

    /**
     * Foto de un producto VISIBLE, sin cuenta. Cualquier otro caso (producto
     * oculto, pausado, de un proveedor suspendido, catálogo público apagado,
     * foto inexistente): 404, igual que si no existiera.
     */
    public function photo(Request $request, SupplierProductPhoto $photo): StreamedResponse
    {
        $this->ensureEnabled();
        $product = $photo->product()->first();
        abort_unless($product && $product->isVisible(), 404);

        $path = $request->boolean('thumb') ? $photo->thumb_path : $photo->path;
        abort_unless(is_string($path) && str_starts_with($path, 'marketplace/') && ! str_contains($path, '..') && Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->response($path, null, [
            'Content-Type' => 'image/jpeg',
            // Corto: si el producto deja de verse, la foto deja de servirse enseguida.
            'Cache-Control' => 'public, max-age=600',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'",
        ]);
    }

    /** @return array<string, int> rubro => productos visibles */
    public static function categoryCounts(): array
    {
        return SupplierProduct::visible()->reorder()->selectRaw('supplier_products.category as category, count(*) as total')
            ->groupBy('supplier_products.category')->pluck('total', 'category')->map(fn ($n) => (int) $n)->all();
    }

    public static function slug(SupplierProduct $product): string
    {
        return Str::slug(Str::limit($product->name, 80, '')) ?: 'repuesto';
    }

    public static function productUrl(SupplierProduct $product): string
    {
        return route('marketplace.product', ['product' => $product->id, 'slug' => self::slug($product)]);
    }

    public static function photoUrl(SupplierProductPhoto $photo, bool $thumb = false): string
    {
        return route('marketplace.image', ['photo' => $photo->id] + ($thumb ? ['thumb' => 1] : []));
    }
}
