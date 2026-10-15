<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\Building;
use App\Models\ClientClaim;
use App\Models\ClientClaimPhoto;
use App\Models\Elevator;
use App\Services\Claims\ClientClaimService;
use App\Support\Portal\PortalAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Reclamos del portal: la persona ve y crea SOLO sus reclamos, de los
 * edificios que tiene autorizados en la empresa actual. Cualquier otro
 * reclamo responde 404 (sin pistas). Las notas internas nunca llegan acá.
 */
class PortalClaimController extends Controller
{
    private function companyId(Request $request): int
    {
        abort_unless(ClientClaimService::portalCanClaim($request->user()), 403);

        return (int) PortalAccess::currentCompanyId($request->user());
    }

    /** Un reclamo propio de la empresa actual; si no, 404. */
    private function own(Request $request, int $id): ClientClaim
    {
        return ClientClaim::withoutGlobalScopes()
            ->where('company_id', $this->companyId($request))
            ->where('created_by', $request->user()->id)
            ->findOrFail($id);
    }

    private function buildings(Request $request)
    {
        $user = $request->user();
        $companyId = $this->companyId($request);
        $membership = PortalAccess::currentMembership($user);

        return Building::withoutGlobalScopes()->where('company_id', $companyId)->whereNull('deleted_at')
            ->where('client_id', $membership?->client_id ?? 0)
            ->whereIn('id', PortalAccess::buildingIds($user, $companyId)->all() ?: [0])
            ->orderBy('name')->get(['id', 'name', 'address']);
    }

    public function index(Request $request): View
    {
        $claims = ClientClaim::withoutGlobalScopes()
            ->where('company_id', $this->companyId($request))
            ->where('created_by', $request->user()->id)
            ->with(['building', 'elevator'])
            ->latest('created_at')->paginate(15);

        return view('portal.claims.index', ['claims' => $claims]);
    }

    public function create(Request $request): View
    {
        $buildings = $this->buildings($request);
        $elevators = Elevator::withoutGlobalScopes()->whereIn('building_id', $buildings->pluck('id')->all() ?: [0])
            ->where('is_active', true)->orderBy('label')->get(['id', 'building_id', 'label'])->groupBy('building_id');

        return view('portal.claims.create', [
            'buildings' => $buildings,
            'elevators' => $elevators,
            'needsPhone' => blank($request->user()->phone),
        ]);
    }

    public function store(Request $request, ClientClaimService $claims): RedirectResponse
    {
        $this->companyId($request);
        $data = $request->validate([
            'building_id' => ['required', 'integer'],
            'elevator_id' => ['nullable', 'integer'],
            'category' => ['required', Rule::in(array_keys(ClientClaim::CATEGORIES))],
            'description' => ['required', 'string', 'min:5', 'max:2000'],
            'occurred_at' => ['nullable', 'date', 'before_or_equal:now', 'after:-1 year'],
            'contact_phone' => ['nullable', 'string', 'max:40', 'regex:/^[0-9+()\s.\-]{6,40}$/'],
            'photos' => ['nullable', 'array', 'max:'.ClientClaimService::MAX_PHOTOS],
            'photos.*' => ['file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ], [
            'building_id.required' => 'Elegí el edificio.',
            'category.required' => 'Elegí qué pasó.',
            'description.required' => 'Contá brevemente qué pasó.',
            'description.min' => 'Contá brevemente qué pasó.',
            'photos.max' => 'Podés adjuntar hasta '.ClientClaimService::MAX_PHOTOS.' fotos.',
            'photos.*.image' => 'Las fotos tienen que ser imágenes JPG, PNG o WebP.',
            'photos.*.mimes' => 'Las fotos tienen que ser imágenes JPG, PNG o WebP.',
            'photos.*.max' => 'Cada foto puede pesar hasta 5 MB.',
            'contact_phone.regex' => 'Escribí un teléfono válido.',
            'occurred_at.before_or_equal' => 'La fecha no puede ser futura.',
        ]);

        $claim = $claims->create($request->user(), $data, $request->file('photos', []));

        return redirect()->route('portal.claims.show', $claim->id)->with('claim_created', true);
    }

    public function show(Request $request, int $claim): View
    {
        $claim = $this->own($request, $claim)->load(['building', 'elevator', 'photos', 'publicEvents.user']);

        return view('portal.claims.show', ['claim' => $claim]);
    }

    public function photo(Request $request, int $claim, int $photo): StreamedResponse
    {
        $claim = $this->own($request, $claim);
        $file = ClientClaimPhoto::where('client_claim_id', $claim->id)->where('company_id', $claim->company_id)->findOrFail($photo);

        return self::stream($file);
    }

    /** Sirve una foto del disco privado (también lo usa el panel, con su propio permiso). */
    public static function stream(ClientClaimPhoto $file): StreamedResponse
    {
        abort_unless(str_starts_with($file->path, 'client-claims/'.$file->company_id.'/') && ! str_contains($file->path, '..') && Storage::disk('local')->exists($file->path), 404);

        return Storage::disk('local')->response($file->path, null, [
            'Content-Type' => in_array($file->mime, ['image/jpeg', 'image/png', 'image/webp'], true) ? $file->mime : 'application/octet-stream',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Disposition' => 'inline',
        ]);
    }
}
