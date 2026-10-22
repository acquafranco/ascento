<?php

namespace App\Filament\Pages\Marketplace;

use App\Models\Marketplace\MarketplaceFavorite;
use App\Models\Marketplace\MarketplaceReport;
use App\Models\Marketplace\MarketplaceRequest;
use App\Models\Marketplace\Supplier;
use App\Models\Marketplace\SupplierProduct;
use App\Services\Marketplace\MarketplaceNotifier;
use App\Services\Marketplace\RequestCart;
use App\Support\Marketplace\BuyerAccess;
use App\Support\Marketplace\Catalog;
use App\Support\Marketplace\CatalogRanking;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Livewire\Attributes\Url;

/**
 * Catálogo de repuestos para las empresas de mantenimiento: buscar y filtrar,
 * ver el producto y el perfil del proveedor, comparar, guardar en favoritos,
 * reportar y sumar al pedido de cotización. Solo productos visibles
 * (SupplierProduct::visible); todo id que llega del navegador se vuelve a
 * buscar en el servidor.
 */
class PartsCatalog extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-building-storefront';

    protected static string|\UnitEnum|null $navigationGroup = 'Operaciones';

    protected static ?string $navigationLabel = 'Repuestos';

    protected static ?int $navigationSort = 12;

    protected static ?string $slug = 'repuestos';

    protected static ?string $title = 'Repuestos';

    protected string $view = 'filament.pages.marketplace.catalog';

    #[Url]
    public string $q = '';

    #[Url]
    public string $rubro = '';

    #[Url]
    public string $marca = '';

    #[Url]
    public string $disponibilidad = '';

    #[Url]
    public string $precio = '';

    #[Url]
    public ?int $proveedor = null;

    #[Url]
    public bool $favoritos = false;

    #[Url]
    public ?int $producto = null;

    /** Ids a comparar («1,2,3»). */
    #[Url]
    public string $comparar = '';

    /** «comparar»: la tabla comparativa en vez del listado. */
    #[Url]
    public string $vista = '';

    public int $perPage = 24;

    public int $quantity = 1;

    public ?int $reportingId = null;

    public string $reportReason = '';

    public string $reportDetails = '';

    public static function canAccess(): bool
    {
        return BuyerAccess::canBrowse(auth()->user());
    }

    public function getTitle(): string
    {
        return match (true) {
            $this->producto !== null => 'Producto',
            $this->vista === 'comparar' => 'Comparar repuestos',
            default => 'Repuestos',
        };
    }

    public function getSubheading(): ?string
    {
        return 'Productos de proveedores aprobados por Ascento. Pedí cotizaciones sin compromiso: el pago y la entrega se coordinan con cada proveedor.';
    }

    private function companyId(): int
    {
        return (int) auth()->user()->company_id;
    }

    private function cart(): RequestCart
    {
        return new RequestCart($this->companyId());
    }

    /** @return list<int> */
    public function compareIds(): array
    {
        return array_slice(array_values(array_unique(array_filter(array_map('intval', explode(',', $this->comparar))))), 0, Catalog::MAX_COMPARE);
    }

    private function visible(int $id): SupplierProduct
    {
        return SupplierProduct::visible()->with(['supplier', 'photos'])->findOrFail($id);
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['q', 'rubro', 'marca', 'disponibilidad', 'precio', 'favoritos'], true)) {
            $this->perPage = 24;
        }
    }

    public function showMore(): void
    {
        $this->perPage = min($this->perPage + 24, 240);
    }

    public function clearFilters(): void
    {
        $this->reset(['q', 'rubro', 'marca', 'disponibilidad', 'precio', 'proveedor', 'favoritos']);
    }

    public function toggleFavorite(int $id): void
    {
        $product = $this->visible($id);
        $row = MarketplaceFavorite::where('company_id', $this->companyId())->where('supplier_product_id', $product->id);
        if ($row->exists()) {
            $row->delete();
        } else {
            MarketplaceFavorite::firstOrCreate(['company_id' => $this->companyId(), 'supplier_product_id' => $product->id]);
        }
    }

    public function toggleCompare(int $id): void
    {
        $ids = $this->compareIds();
        if (in_array($id, $ids, true)) {
            $ids = array_values(array_diff($ids, [$id]));
        } else {
            $this->visible($id);
            if (count($ids) >= Catalog::MAX_COMPARE) {
                Notification::make()->title('Podés comparar hasta '.Catalog::MAX_COMPARE.' productos')->warning()->send();

                return;
            }
            $ids[] = $id;
        }
        $this->comparar = implode(',', $ids);
    }

    public function addToRequest(int $id): void
    {
        abort_unless(BuyerAccess::canOrder(auth()->user()), 403);
        $product = $this->visible($id);
        $quantity = max(1, min(Catalog::MAX_QUANTITY, (int) $this->quantity));
        $cart = $this->cart();
        if (! array_key_exists($product->id, $cart->all()) && $cart->count() >= Catalog::MAX_ITEMS) {
            Notification::make()->title('Tu pedido ya tiene '.Catalog::MAX_ITEMS.' productos')->body('Envialo antes de sumar más.')->warning()->send();

            return;
        }
        $cart->add($product->id, $quantity);
        $this->quantity = 1;

        Notification::make()->title('Agregado al pedido')->body($product->name.' × '.$quantity)->success()
            ->actions([Action::make('ver')->label('Ver mi pedido')->url(PartsRequests::getUrl())])->send();
    }

    public function startReport(int $id): void
    {
        $this->visible($id);
        $this->reportingId = $id;
        $this->reportReason = '';
        $this->reportDetails = '';
    }

    public function submitReport(): void
    {
        $this->validate([
            'reportingId' => ['required', 'integer'],
            'reportReason' => ['required', 'in:'.implode(',', array_keys(Catalog::REPORT_REASONS))],
            'reportDetails' => ['nullable', 'string', 'max:1000'],
        ], ['reportReason.required' => 'Elegí un motivo.']);

        $product = $this->visible((int) $this->reportingId);
        // Un reporte abierto por empresa y producto: repetirlo no suma ruido.
        $report = MarketplaceReport::firstOrCreate(
            ['supplier_product_id' => $product->id, 'company_id' => $this->companyId(), 'status' => 'open'],
            ['user_id' => auth()->id(), 'reason' => $this->reportReason, 'details' => filled($this->reportDetails) ? trim($this->reportDetails) : null],
        );
        if ($report->wasRecentlyCreated) {
            app(MarketplaceNotifier::class)->team('Reporte de una publicación', 'Reportaron «'.$product->name.'»: '.$report->reasonLabel().'.', '/admin/marketplace/reportes', 'report:'.$report->id);
        }

        $this->reportingId = null;
        Notification::make()->title('Gracias, lo vamos a revisar')->success()->send();
    }

    protected function getViewData(): array
    {
        $favorites = MarketplaceFavorite::where('company_id', $this->companyId())->pluck('supplier_product_id')->all();
        $base = [
            'favorites' => $favorites,
            'compare' => $this->compareIds(),
            'cartCount' => $this->cart()->count(),
            'canOrder' => BuyerAccess::canOrder(auth()->user()),
        ];

        if ($this->producto !== null) {
            $product = $this->visible($this->producto);

            return $base + ['mode' => 'product', 'product' => $product, 'operations' => $product->supplier->completedOperations(),
                'history' => MarketplaceRequest::forCompany($this->companyId())->where('supplier_id', $product->supplier_id)->latest()->limit(5)->get()];
        }

        if ($this->vista === 'comparar') {
            return $base + ['mode' => 'compare', 'products' => $this->compareProducts()];
        }

        $supplier = $this->proveedor ? Supplier::operating()->findOrFail($this->proveedor) : null;
        $query = $this->query($favorites);

        return $base + [
            'mode' => 'list',
            'supplierProfile' => $supplier,
            'operations' => $supplier?->completedOperations(),
            'products' => (clone $query)->limit($this->perPage)->get(),
            'total' => (clone $query)->reorder()->count(),
            'brands' => SupplierProduct::visible()->whereNotNull('brand')->distinct()->orderBy('brand')->limit(150)->pluck('brand'),
            'compareProducts' => $this->compareProducts(),
        ];
    }

    private function compareProducts(): Collection
    {
        $ids = $this->compareIds();

        return $ids === [] ? collect() : SupplierProduct::visible()->with(['supplier', 'photos'])->whereIn('id', $ids)->get()->sortBy(fn ($p) => array_search($p->id, $ids, true))->values();
    }

    private function query(array $favorites): Builder
    {
        $like = fn (string $v) => '%'.addcslashes($v, '\\%_').'%';
        $words = trim($this->q) === '' ? [] : array_slice(preg_split('/\s+/', trim(mb_substr($this->q, 0, 120))), 0, 6);

        $query = SupplierProduct::visible()->with(['supplier:id,name', 'photos'])
            ->when($words !== [], function (Builder $q) use ($like, $words) {
                foreach ($words as $word) {
                    $q->where(fn (Builder $w) => $w->where('name', 'like', $like($word))->orWhere('brand', 'like', $like($word))->orWhere('model', 'like', $like($word))
                        ->orWhere('part_number', 'like', $like($word))->orWhere('compatibility', 'like', $like($word))->orWhere('description', 'like', $like($word)));
                }
            })
            ->when(isset(Catalog::CATEGORIES[$this->rubro]), fn (Builder $q) => $q->where('category', $this->rubro))
            ->when($this->marca !== '', fn (Builder $q) => $q->where('brand', $this->marca))
            ->when(isset(Catalog::AVAILABILITY[$this->disponibilidad]), fn (Builder $q) => $q->where('availability', $this->disponibilidad))
            ->when(isset(Catalog::PRICE_MODES[$this->precio]), fn (Builder $q) => $q->where('price_mode', $this->precio))
            ->when($this->proveedor, fn (Builder $q) => $q->where('supplier_id', $this->proveedor))
            ->when($this->favoritos, fn (Builder $q) => $q->whereIn('supplier_products.id', $favorites ?: [0]));

        // Relevancia, compatibilidad, disponibilidad, destacado y rotación (CatalogRanking).
        return CatalogRanking::apply($query, $words, $this->companyId());
    }
}
