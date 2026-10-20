<?php

namespace App\Http\Controllers;

use App\Enums\PlanFeature;
use App\Services\Parachute\ParachuteService;
use App\Support\Plans\PlanGuard;
use Illuminate\Http\Request;

/**
 * «Paracaídas» en la app del técnico: cuándo corresponde la prueba de cada
 * ascensor que sigue (edificios donde hace la inspección, o donde es el
 * inspector o el responsable asignado), sin tener que entrar edificio por
 * edificio. Solo consulta: la prueba se registra al firmar el remito (o la
 * carga la oficina). Las fechas salen de la periodicidad que configuró la
 * oficina; si falta, se dice que falta (no se inventa una).
 */
class ParachuteController extends Controller
{
    public function index(Request $request, ParachuteService $parachute)
    {
        $user = $request->user();

        abort_unless(PlanGuard::companyAllows((int) $user->company_id, PlanFeature::ParachuteTests), 404);

        $rows = $parachute->forTechnician($user);
        $order = array_keys(ParachuteService::STATUSES);

        return view('parachute.index', [
            'groups' => $rows->groupBy('status')->sortBy(fn ($group, $status) => array_search($status, $order, true)),
            'total' => $rows->count(),
        ]);
    }
}
