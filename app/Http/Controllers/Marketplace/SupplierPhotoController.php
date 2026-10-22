<?php

namespace App\Http\Controllers\Marketplace;

use App\Http\Controllers\Controller;
use App\Models\Marketplace\SupplierProductPhoto;
use App\Support\Marketplace\BuyerAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Fotos de productos del marketplace (disco privado). Las ve: el proveedor
 * dueño, el SuperAdmin y los compradores habilitados, solo si el producto
 * está visible. Cualquier otro caso: 404.
 */
class SupplierPhotoController extends Controller
{
    public function __invoke(Request $request, SupplierProductPhoto $photo): StreamedResponse
    {
        $user = $request->user();
        $product = $photo->product()->with('supplier')->first();

        $allowed = $product && (
            ($user->isSupplierUser() && (int) $user->supplier_id === (int) $photo->supplier_id)
            || $user->isSuperAdmin()
            || (BuyerAccess::canBrowse($user) && $product->isVisible())
        );
        abort_unless($allowed, 404);

        $path = $request->boolean('thumb') ? $photo->thumb_path : $photo->path;
        abort_unless(str_starts_with($path, 'marketplace/') && ! str_contains($path, '..') && Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->response($path, null, [
            'Content-Type' => 'image/jpeg',
            'Cache-Control' => 'private, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'",
        ]);
    }
}
