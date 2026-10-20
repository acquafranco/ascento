<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Portal\PortalClaimController;
use App\Models\ClientClaim;
use App\Models\ClientClaimPhoto;
use App\Services\Claims\ClientClaimService;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Un reporte del QR, para el técnico que recibió el aviso: solo si es de su
 * empresa y tiene asignado ese edificio (mantenimiento o inspección). Solo
 * lectura: categoría, ascensor, descripción, fecha, foto y estado. La gestión
 * (estado, orden de trabajo) la hace la oficina en el panel. Cualquier otro
 * caso: 404, sin pistas.
 */
class TechnicianQrReportController extends Controller
{
    private function claim(Request $request, int $id): ClientClaim
    {
        $user = $request->user();
        $claim = ClientClaim::withoutGlobalScopes()->where('company_id', $user->company_id)->where('origin', ClientClaim::ORIGIN_QR)->find($id);

        abort_unless($claim && $user->role === 'technician'
            && app(ClientClaimService::class)->buildingTechnicians($claim)->contains('id', $user->id), 404);

        return $claim;
    }

    public function show(Request $request, $company, int $claim): View
    {
        $claim = $this->claim($request, $claim)->load(['building', 'elevator', 'photos']);

        // Abrirlo marca leído su aviso (y así figura en el panel como abierto).
        $request->user()->unreadNotifications()->where('dedupe_key', 'like', 'claim:'.$claim->id.':qr_created%')->update(['read_at' => now()]);

        return view('qr-reports.technician', ['claim' => $claim]);
    }

    public function photo(Request $request, $company, int $claim, int $photo): StreamedResponse
    {
        $claim = $this->claim($request, $claim);
        $file = ClientClaimPhoto::where('client_claim_id', $claim->id)->where('company_id', $claim->company_id)->findOrFail($photo);

        return PortalClaimController::stream($file);
    }
}
