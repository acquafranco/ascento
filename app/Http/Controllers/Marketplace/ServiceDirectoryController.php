<?php

namespace App\Http\Controllers\Marketplace;

use App\Http\Controllers\Controller;
use App\Models\Marketplace\ServiceProfile;
use App\Support\Countries;
use App\Support\Marketplace\ServiceAccess;
use App\Support\Marketplace\ServiceDirectory;
use App\Support\Provinces;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Catálogo público «Profesionales y servicios» (sin iniciar sesión). Muestra
 * solo perfiles visibles y solo información profesional pública: nunca
 * correos, teléfonos, domicilios ni datos internos de la cuenta.
 */
class ServiceDirectoryController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $request->only(['q', 'servicio', 'marca', 'pais', 'provincia', 'modalidad']);
        $filters = array_map(fn ($v) => is_string($v) ? mb_substr(trim($v), 0, 80) : null, $filters);
        $page = ServiceDirectory::search($filters);
        // Perfiles de empresas sin acceso vigente: no se ofrecen (no podrían responder).
        $page->setCollection($page->getCollection()->filter(fn (ServiceProfile $p) => $p->company_id === null || (bool) $p->company?->hasActiveAccess())->values());

        return view('services.index', [
            'profiles' => $page,
            'filters' => $filters,
            'categories' => ServiceDirectory::categoryOptions(),
            'brands' => ServiceDirectory::brandOptions(),
            'countries' => Countries::options(),
            'provinces' => Provinces::LIST,
        ]);
    }

    public function show(Request $request, string $slug): View
    {
        $profile = ServiceProfile::where('slug', $slug)->with(['categories' => fn ($c) => $c->where('is_active', true)])->first();
        abort_unless($profile && $profile->isVisible(), 404);
        $user = $request->user();

        return view('services.show', [
            'profile' => $profile,
            'canRequest' => ServiceAccess::canRequest($user) && (int) $profile->company_id !== (int) $user?->company_id,
            'loggedIn' => $user !== null,
        ]);
    }
}
