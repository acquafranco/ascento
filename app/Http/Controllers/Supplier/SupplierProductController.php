<?php

namespace App\Http\Controllers\Supplier;

use App\Http\Controllers\Controller;
use App\Models\Marketplace\SupplierProduct;
use App\Models\Marketplace\SupplierProductPhoto;
use App\Services\Marketplace\PublicationLimits;
use App\Services\Media\PhotoProcessor;
use App\Support\Marketplace\Catalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
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
        SupplierProduct::OVER_LIMIT => [SupplierProduct::PUBLISHED, SupplierProduct::PAUSED, SupplierProduct::RETIRED],
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

        return view('suppliers.products.index', ['products' => $products, 'status' => $status, 'usage' => PublicationLimits::usage($request->user()->supplier)]);
    }

    public function create(Request $request): View
    {
        SupplierAreaController::ensureCanPublish($request->user()->supplier);

        return view('suppliers.products.form', ['product' => new SupplierProduct(['price_mode' => 'quote', 'availability' => 'to_order']), 'usage' => PublicationLimits::usage($request->user()->supplier)]);
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
        return view('suppliers.products.form', ['product' => $this->product($request, $product)->load('photos'), 'usage' => PublicationLimits::usage($request->user()->supplier)]);
    }

    public function update(Request $request, int $product): RedirectResponse
    {
        SupplierAreaController::ensureCanEdit($request->user()->supplier);
        $model = $this->product($request, $product);
        $model->fill($this->validated($request))->save();

        return redirect()->route('suppliers.products.edit', $model->id)->with('status', 'Cambios guardados.');
    }

    public function status(Request $request, int $product, PublicationLimits $limits): RedirectResponse
    {
        SupplierAreaController::ensureCanEdit($request->user()->supplier);
        $model = $this->product($request, $product);
        $to = (string) $request->input('estado');

        if (! in_array($to, self::TRANSITIONS[$model->status] ?? [], true)) {
            throw ValidationException::withMessages(['estado' => 'Esta publicación no se puede pasar a ese estado.']);
        }
        if ($to === SupplierProduct::PUBLISHED) {
            // Con bloqueo y conteo en la base: dos pedidos a la vez no pasan el límite del plan.
            $model = $limits->publish($model);
        } else {
            $model->forceFill(['status' => $to, 'limit_hidden_at' => null])->save();
        }

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

    /** Premium: «conservar publicado si vuelvo al plan gratuito». */
    public function keep(Request $request, int $product, PublicationLimits $limits): RedirectResponse
    {
        SupplierAreaController::ensureCanEdit($request->user()->supplier);
        $limits->setKeep($this->product($request, $product), $request->boolean('conservar'));

        return back()->with('status', $request->boolean('conservar') ? 'Lo vamos a conservar publicado si volvés al plan gratuito.' : 'Listo, ya no está marcado para conservar.');
    }

    /** Vuelve a publicar lo que quedó fuera del límite, mientras haya lugar en el plan. */
    public function restoreOverLimit(Request $request, PublicationLimits $limits): RedirectResponse
    {
        SupplierAreaController::ensureCanEdit($request->user()->supplier);
        $n = $limits->restoreOverLimit($request->user()->supplier);

        return back()->with('status', $n ? "Volviste a publicar {$n} ".($n === 1 ? 'producto' : 'productos').'.' : 'No hay lugar en tu plan o no hay productos fuera del límite.');
    }

    /**
     * Sube una o varias fotos (`fotos[]`; `foto` sigue funcionando). Cada archivo
     * se vuelve a codificar (PhotoProcessor: contenido real, sin metadatos); el
     * nombre que manda el navegador no se usa. Si una falla o se pasa del
     * máximo, no queda ningún archivo nuevo ni ninguna fila.
     */
    public function storePhoto(Request $request, int $product, PhotoProcessor $photos): RedirectResponse
    {
        SupplierAreaController::ensureCanEdit($request->user()->supplier);
        $model = $this->product($request, $product);
        // Formulario anterior (un solo campo «foto»): mismo flujo y errores con su nombre.
        $single = ! $request->exists('fotos') && $request->exists('foto');
        $input = $single ? ['fotos' => $request->hasFile('foto') ? [$request->file('foto')] : null] : ['fotos' => $request->file('fotos')];
        try {
            return $this->storePhotos($input, $model, $photos);
        } catch (ValidationException $e) {
            throw $single ? ValidationException::withMessages(['foto' => collect($e->errors())->flatten()->first()]) : $e;
        }
    }

    private function storePhotos(array $input, SupplierProduct $model, PhotoProcessor $photos): RedirectResponse
    {

        $max = Catalog::MAX_PHOTOS;
        $mb = Catalog::MAX_PHOTO_KB / 1024;
        Validator::make($input, [
            'fotos' => ['required', 'array', 'min:1', 'max:'.$max],
            'fotos.*' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:'.Catalog::MAX_PHOTO_KB],
        ], [
            'fotos.required' => 'Elegí al menos una foto.',
            'fotos.max' => 'Podés subir hasta '.$max.' fotos por producto.',
            'fotos.*.image' => 'Cada archivo tiene que ser una foto (JPG, PNG o WebP).',
            'fotos.*.mimes' => 'Cada archivo tiene que ser una foto (JPG, PNG o WebP).',
            'fotos.*.max' => 'Una de las fotos pesa más de '.$mb.' MB.',
            'fotos.*.uploaded' => 'Una de las fotos no se pudo subir (puede pesar más de '.$mb.' MB).',
        ])->validate();
        $files = array_values((array) $input['fotos']);
        $free = $max - $model->photos()->count();
        if (count($files) > $free) {
            throw ValidationException::withMessages(['fotos' => $free > 0
                ? 'Este producto ya tiene '.($max - $free).' fotos: podés agregar '.$free.' más (máximo '.$max.').'
                : 'Este producto ya tiene las '.$max.' fotos permitidas. Quitá alguna para agregar otra.']);
        }

        $stored = [];
        try {
            foreach ($files as $file) {
                $stored[] = $photos->store($file, 'marketplace/'.$model->supplier_id.'/'.$model->id, 'fotos', 'Foto de producto inválida');
            }
            DB::transaction(function () use ($model, $stored, $max) {
                // Con el producto bloqueado: dos subidas a la vez no pasan el máximo.
                SupplierProduct::whereKey($model->id)->lockForUpdate()->first();
                $count = $model->photos()->count();
                if ($count + count($stored) > $max) {
                    throw ValidationException::withMessages(['fotos' => 'Este producto ya tiene '.$count.' fotos (máximo '.$max.').']);
                }
                $position = (int) $model->photos()->max('position');
                foreach ($stored as $file) {
                    $model->photos()->create(['supplier_id' => $model->supplier_id, 'path' => $file['path'], 'thumb_path' => $file['thumb_path'], 'position' => ++$position]);
                }
            });
        } catch (\Throwable $e) {
            foreach ($stored as $file) {
                Storage::disk('local')->delete([$file['path'], $file['thumb_path']]);
            }
            throw $e;
        }
        $this->renumber($model);

        return back()->with('status', count($stored) === 1 ? 'Foto agregada.' : count($stored).' fotos agregadas.');
    }

    /** La foto pasa a ser la principal (la primera: la que se ve en el catálogo). */
    public function primaryPhoto(Request $request, int $product, int $photo): RedirectResponse
    {
        SupplierAreaController::ensureCanEdit($request->user()->supplier);
        $model = $this->product($request, $product);
        $row = $this->photo($model, $photo);
        $ids = $model->photos()->pluck('id')->reject(fn ($id) => $id === $row->id)->prepend($row->id)->all();
        $this->renumber($model, $ids);

        return back()->with('status', 'Foto principal actualizada.');
    }

    /** Mueve la foto un lugar antes («antes») o después («despues»). */
    public function movePhoto(Request $request, int $product, int $photo): RedirectResponse
    {
        SupplierAreaController::ensureCanEdit($request->user()->supplier);
        $model = $this->product($request, $product);
        $row = $this->photo($model, $photo);
        $request->validate(['direccion' => ['required', Rule::in(['antes', 'despues'])]]);
        $ids = $model->photos()->pluck('id')->all();
        $i = array_search($row->id, $ids, true);
        $j = $request->input('direccion') === 'antes' ? $i - 1 : $i + 1;
        if (isset($ids[$j])) {
            [$ids[$i], $ids[$j]] = [$ids[$j], $ids[$i]];
            $this->renumber($model, $ids);
        }

        return back()->with('status', 'Orden de las fotos actualizado.');
    }

    public function destroyPhoto(Request $request, int $product, int $photo): RedirectResponse
    {
        SupplierAreaController::ensureCanEdit($request->user()->supplier);
        $model = $this->product($request, $product);
        $row = $this->photo($model, $photo);
        $files = [$row->path, $row->thumb_path];
        $row->delete();
        // Primero la fila y después los archivos: nunca queda una foto que apunte a un archivo borrado.
        Storage::disk('local')->delete($files);
        $this->renumber($model);

        return back()->with('status', 'Foto eliminada.');
    }

    /** Solo una foto de ESTE producto (que ya es del proveedor de quien pide): cualquier otra → 404. */
    private function photo(SupplierProduct $model, int $photo): SupplierProductPhoto
    {
        return SupplierProductPhoto::where('supplier_product_id', $model->id)->where('supplier_id', $model->supplier_id)->findOrFail($photo);
    }

    /** Posiciones 1..n en el orden dado (por defecto, el actual). */
    private function renumber(SupplierProduct $model, ?array $ids = null): void
    {
        $ids ??= $model->photos()->pluck('id')->all();
        DB::transaction(function () use ($model, $ids) {
            foreach (array_values($ids) as $i => $id) {
                SupplierProductPhoto::where('supplier_product_id', $model->id)->whereKey($id)->update(['position' => $i + 1]);
            }
        });
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
