<?php

namespace App\Http\Controllers\Supplier;

use App\Http\Controllers\Controller;
use App\Models\Marketplace\ServiceCategory;
use App\Models\Marketplace\ServiceContactRequest;
use App\Models\Marketplace\ServiceProfile;
use App\Services\Marketplace\ServiceContacts;
use App\Services\Marketplace\ServiceProfiles;
use App\Support\Marketplace\ServiceAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Perfil profesional y consultas recibidas de una cuenta de proveedor
 * (/proveedores/perfil-profesional y /proveedores/solicitudes). Las reglas
 * están en ServiceProfiles y ServiceContacts; acá solo se ve lo propio.
 */
class SupplierServiceController extends Controller
{
    public function edit(Request $request): View
    {
        $profile = ServiceAccess::profileOf($request->user());

        return view('suppliers.services.profile', [
            'profile' => $profile,
            'categories' => ServiceCategory::active()->get(),
            'selected' => $profile ? $profile->categories->pluck('id')->all() : [],
        ]);
    }

    public function update(Request $request, ServiceProfiles $profiles): RedirectResponse
    {
        $data = $request->validate(ServiceProfiles::rules(), ServiceProfiles::messages());
        $data['covers_whole_country'] = $request->boolean('covers_whole_country');
        $profiles->save($request->user(), $data);

        return redirect()->route('suppliers.service-profile.edit')->with('status', 'Perfil profesional guardado.');
    }

    public function publish(Request $request, ServiceProfiles $profiles): RedirectResponse
    {
        $profile = ServiceAccess::profileOf($request->user());
        abort_unless($profile, 404);
        $profiles->publish($request->user(), $profile);

        return back()->with('status', $profile->fresh()->approved_at ? 'Perfil publicado.' : 'Perfil enviado a revisión: lo vas a ver en el catálogo cuando Ascento lo apruebe.');
    }

    public function archive(Request $request, ServiceProfiles $profiles): RedirectResponse
    {
        $profile = ServiceAccess::profileOf($request->user());
        abort_unless($profile, 404);
        $profiles->archive($request->user(), $profile);

        return back()->with('status', 'Perfil archivado: ya no se ve en el catálogo. Tus solicitudes se conservan.');
    }

    public function index(Request $request): View
    {
        $profile = ServiceAccess::profileOf($request->user());
        $tab = $request->query('estado') === 'cerradas' ? 'cerradas' : 'abiertas';

        return view('suppliers.services.index', [
            'profile' => $profile,
            'tab' => $tab,
            'rows' => $profile
                ? ServiceContactRequest::forProfile($profile->id)
                    ->when($tab === 'abiertas', fn ($q) => $q->where('status', '!=', ServiceContactRequest::CLOSED), fn ($q) => $q->where('status', ServiceContactRequest::CLOSED))
                    ->latest('last_message_at')->paginate(25)->withQueryString()
                : null,
        ]);
    }

    public function show(Request $request, string $reference): View
    {
        $row = $this->find($request, $reference);

        return view('suppliers.services.show', ['row' => $row->load('messages'), 'profile' => $row->profile]);
    }

    public function reply(Request $request, string $reference, ServiceContacts $contacts): RedirectResponse
    {
        $contacts->reply($request->user(), $this->find($request, $reference), (string) $request->input('mensaje'));

        return back()->with('status', 'Respuesta enviada. Le avisamos a la empresa.');
    }

    public function close(Request $request, string $reference, ServiceContacts $contacts): RedirectResponse
    {
        $contacts->close($request->user(), $this->find($request, $reference));

        return back()->with('status', 'Solicitud cerrada. Queda en tu historial.');
    }

    /** Solo las dirigidas al perfil de ESTA cuenta: cualquier otra referencia da 404. */
    private function find(Request $request, string $reference): ServiceContactRequest
    {
        $profile = ServiceAccess::profileOf($request->user());
        abort_unless($profile instanceof ServiceProfile, 404);

        return ServiceContactRequest::forProfile($profile->id)->where('reference', $reference)->firstOrFail();
    }
}
