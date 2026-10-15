<?php

namespace App\Http\Controllers;

use App\Enums\PermissionArea;
use App\Http\Controllers\Portal\PortalClaimController;
use App\Models\ClientClaimPhoto;
use App\Support\Access\Access;
use App\Support\CompanyContext;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Foto de un reclamo para el panel: dueño, SuperAdmin dentro de esa empresa
 * o administrativo con «Órdenes de trabajo». Cualquier otro caso, 404.
 * (El cliente las ve por el portal: PortalClaimController::photo.)
 */
class ClientClaimPhotoController extends Controller
{
    public function __invoke(Request $request, int $photo): StreamedResponse
    {
        $user = $request->user();
        $file = ClientClaimPhoto::find($photo);

        abort_unless(
            $file && Access::panelUser($user) && Access::can($user, PermissionArea::WorkOrders)
            && CompanyContext::currentId() === (int) $file->company_id,
            404,
        );

        return PortalClaimController::stream($file);
    }
}
