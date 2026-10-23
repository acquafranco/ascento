<?php

namespace App\Http\Controllers;

use App\Models\Building;
use App\Models\Company;
use App\Models\SiteVisit;
use App\Models\WorkOrder;
use App\Services\WorkOrders\AttendanceTimes;
use App\Services\WorkOrders\SiteVisits;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Visita técnica desde la app del técnico (SiteVisits): «Llegué al edificio»,
 * «Finalizar visita y retirarme» y el cierre excepcional sin salida.
 *
 * Vuelve siempre a una pantalla de la app (nunca a una URL que mande el
 * formulario): la orden, el formulario del remito o el Inicio.
 */
class SiteVisitController extends Controller
{
    public function arrive(Request $request, Company $company, SiteVisits $visits)
    {
        $user = $request->user();
        $data = $request->validate([
            'building_id' => ['required', 'integer'],
            'work_order_id' => ['nullable', 'integer'],
            'return' => ['nullable', 'in:remito,inicio'],
        ]);
        $building = Building::withoutGlobalScopes()->where('company_id', $user->company_id)->findOrFail($data['building_id']);
        $order = filled($data['work_order_id'] ?? null)
            ? WorkOrder::withoutGlobalScopes()->where('company_id', $user->company_id)->findOrFail($data['work_order_id'])
            : null;

        try {
            [$visit, $now] = $visits->arrive($user, $building, $order);
        } catch (ValidationException $e) {
            return back()->with('warning', collect($e->errors())->flatten()->first());
        }
        $at = AttendanceTimes::time($visit->arrived_at);

        return back()->with($now ? 'success' : 'warning', $now ? 'Llegada registrada: '.$at.'. Ya podés hacer los trabajos y los remitos.' : 'La llegada ya estaba registrada: '.$at.'.');
    }

    public function leave(Request $request, Company $company, int $siteVisit, SiteVisits $visits)
    {
        $user = $request->user();
        $visit = SiteVisit::withoutGlobalScopes()->where('company_id', $user->company_id)->where('user_id', $user->id)->findOrFail($siteVisit);

        try {
            [$visit, $now] = $visits->leave($user, $visit);
        } catch (ValidationException $e) {
            return back()->with('warning', collect($e->errors())->flatten()->first());
        }
        $at = AttendanceTimes::time($visit->left_at);
        $pending = SiteVisits::pending($visit)->count();

        return redirect()->route('dashboard', ['company' => $company->slug])
            ->with($now ? 'success' : 'warning', ($now ? 'Visita finalizada. Salida registrada: '.$at.'.' : 'La salida ya estaba registrada: '.$at.'.')
                .($now && $pending ? ' '.($pending === 1 ? 'Una orden quedó' : $pending.' órdenes quedaron').' en curso, sin remito.' : ''));
    }

    public function close(Request $request, Company $company, int $siteVisit, SiteVisits $visits)
    {
        $user = $request->user();
        $visit = SiteVisit::withoutGlobalScopes()->where('company_id', $user->company_id)->where('user_id', $user->id)->findOrFail($siteVisit);
        $data = $request->validate(['close_note' => ['required', 'string', 'max:500']], ['close_note.required' => 'Contá por qué se cierra sin salida.']);

        try {
            $visits->closeWithoutExit($user, $visit, $data['close_note']);
        } catch (ValidationException $e) {
            return back()->with('warning', collect($e->errors())->flatten()->first());
        }

        return redirect()->route('dashboard', ['company' => $company->slug])
            ->with('success', 'Visita cerrada sin salida registrada. La oficina ve el motivo.');
    }
}
