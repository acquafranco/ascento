<?php

namespace App\Http\Controllers\Supplier;

use App\Http\Controllers\Controller;
use App\Models\Marketplace\SupplierProduct;
use App\Models\Marketplace\SupplierProductPhoto;
use App\Services\Media\PhotoProcessor;
use App\Support\Marketplace\Catalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Catálogo del proveedor: crear, editar, publicar, pausar, retirar y fotos.
 * Cada producto se busca DENTRO del proveedor de quien pide (otro → 404); el
 * proveedor, el estado de moderación y el destacado nunca vienen del formulario.
 */
class SupplierProductController extends Controller
{
    /** De dónde a dónde puede mover el proveedor una publicación. */
    public const TRANSITIONS = [
        SupplierProduct::DRAFT => [SupplierProduct::PUBLISHED, SupplierProduct::RETIRED],
        SupplierProduct::PUBLISHED => [SupplierProduct::PAUSED, SupplierProduct::RETIRED],
        SupplierProduct::PAUSED => [SupplierProduct::PUBLISHED, SupplierProduct::RETIRED],
        SupplierProduct::RETIRED => [SupplierProduct::DRAFT],
    ];

    private function product(Request $request, int $id): SupplierProduct
    {
        return SupplierProduct::where('supplier_id', $request->user()->supplier_id)->findOrFail($id);
    }

    public function index(Request $request): View
    {
        $status = $request->query('estado');
        $products = SupplierProduct::where('supplier_id', $request->user()->supplier_id)
            ->when(isset(SupplierProduct::STATUSES[$status]), fn ($q) => $q->where('status', $status))
            ->withCount('photos')->latest('updated_at')->paginate(25)->withQueryString();

        return view('suppliers.products.index', ['products' => $products, 'status' => $status]);
    }

    public function create(Request $request): View
    {
        SupplierAreaController::ensureCanPublish($request->user()->supplier);

        return view('suppliers.products.form', ['product' => new SupplierProduct(['price_mode' => 'quote', 'availability' => 'to_order'])]);
    }

    public function store(Request $request): RedirectResponse
    {
        SupplierAreaController::ensureCanPublish($request->user()->supplier);
        $product = new SupplierProduct($this->validated($request));
        // Moneda del país registrado del proveedor (nunca del formulario).
        $product->forceFill(['supplier_id' => $request->user()->supplier_id, 'status' => SupplierProduct::DRAFT, 'currency' => $request->user()->supplier->currency ?: 'ARS'])->save();

        return redirect()->route('suppliers.products.edit', $product->id)->with('status', 'Producto guardado como borrador. Sumá fotos y publicalo cuando esté listo.');
    }

    public function edit(Request $request, int $product): View
    {
        return view('suppliers.products.form', ['product' => $this->product($request, $product)->load('photos')]);
    }

    public function update(Request $request, int $product): RedirectResponse
    {
        SupplierAreaController::ensureCanEdit($request->user()->supplier);
        $model = $this->product($request, $product);
        $model->fill($this->validated($request))->save();

        return redirect()->route('suppliers.products.edit', $model->id)->with('status', 'Cambios guardados.');
    }

    public function status(Request $request, int $product): RedirectResponse
    {
        SupplierAreaController::ensureCanEdit($request->user()->supplier);
        $model = $this->product($request, $product);
        $to = (string) $request->input('estado');

        if (! in_array($to, self::TRANSITIONS[$model->status] ?? [], true)) {
            throw ValidationException::withMessages(['estado' => 'Esta publicación no se puede pasar a ese estado.']);
        }
        if ($to === SupplierProduct::PUBLISHED) {
            SupplierAreaController::ensureCanPublish($request->user()->supplier);
            if ($model->hidden_by_admin) {
                throw ValidationException::withMessages(['estado' => 'Esta publicación está oculta por moderación: no se puede publicar hasta que Ascento la restaure.']);
            }
        }

        $model->forceFill(['status' => $to, 'published_at' => $to === SupplierProduct::PUBLISHED ? ($model->published_at ?? now()) : $model->published_at])->save();

        $message = match ($to) {
            SupplierProduct::PUBLISHED => $request->user()->supplier->isOperating()
                ? 'Publicado: ya lo ven las empresas.'
                : 'Publicado. Se va a ver cuando aprobemos tu cuenta.',
            SupplierProduct::PAUSED => 'Pausado: no se ve en el catálogo.',
            SupplierProduct::RETIRED => 'Retirado del catálogo.',
            default => 'Volvió a borrador.',
        };

        return back()->with('status', $message);
    }

    public function storePhoto(Request $request, int $product, PhotoProcessor $photos): RedirectResponse
    {
        SupplierAreaController::ensureCanEdit($request->user()->supplier);
        $model = $this->product($request, $product);

        $request->validate(['foto' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:'.Catalog::MAX_PHOTO_KB]], [
            'foto.required' => 'Elegí una foto.',
            'foto.image' => 'El archivo tiene que ser una foto (JPG, PNG o WebP).',
            'foto.mimes' => 'El archivo tiene que ser una foto (JPG, PNG o WebP).',
            'foto.max' => 'La foto pesa demasiado (máximo '.(Catalog::MAX_PHOTO_KB / 1024).' MB).',
        ]);
        if ($model->photos()->count() >= Catalog::MAX_PHOTOS) {
            throw ValidationException::withMessages(['foto' => 'Cada producto puede tener hasta '.Catalog::MAX_PHOTOS.' fotos.']);
        }

        $stored = $photos->store($request->file('foto'), 'marketplace/'.$model->supplier_id.'/'.$model->id, 'foto', 'Foto de producto inválida');
        $model->photos()->create([
            'supplier_id' => $model->supplier_id,
            'path' => $stored['path'],
            'thumb_path' => $stored['thumb_path'],
            'position' => (int) $model->photos()->max('position') + 1,
        ]);

        return back()->with('status', 'Foto agregada.');
    }

    public function destroyPhoto(Request $request, int $product, int $photo): RedirectResponse
    {
        SupplierAreaController::ensureCanEdit($request->user()->supplier);
        $model = $this->product($request, $product);
        $row = SupplierProductPhoto::where('supplier_product_id', $model->id)->findOrFail($photo);
        Storage::disk('local')->delete([$row->path, $row->thumb_path]);
        $row->delete();

        return back()->with('status', 'Foto eliminada.');
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'category' => ['required', Rule::in(array_keys(Catalog::CATEGORIES))],
            'brand' => ['nullable', 'string', 'max:80'],
            'model' => ['nullable', 'string', 'max:120'],
            'part_number' => ['nullable', 'string', 'max:80'],
            'description' => ['nullable', 'string', 'max:5000'],
            'compatibility' => ['nullable', 'string', 'max:2000'],
            'price_mode' => ['required', Rule::in(array_keys(Catalog::PRICE_MODES))],
            'price' => ['nullable', 'required_if:price_mode,fixed', 'numeric', 'min:0.01', 'max:999999999'],
            'availability' => ['required', Rule::in(array_keys(Catalog::AVAILABILITY))],
        ], [
            'price.required_if' => 'Cargá el precio, o elegí «Precio a cotizar».',
            'name.required' => 'Poné un nombre al producto.',
        ]);

        if ($data['price_mode'] === 'quote') {
            $data['price'] = null;
        }
        foreach (['brand', 'model', 'part_number', 'description', 'compatibility'] as $field) {
            $data[$field] = filled($data[$field] ?? null) ? trim($data[$field]) : null;
        }

        return $data;
    }
}
