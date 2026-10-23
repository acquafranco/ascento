<?php

namespace App\Http\Controllers;

use App\Services\Parachute\ParachuteService;
use App\Services\Technicians\TechnicianWorkload;
use App\Services\WorkOrders\SiteVisits;
use Illuminate\Http\Request;

/**
 * Inicio de la app del técnico: qué sigue, qué tiene en curso y las visitas
 * del mes. Los números salen de TechnicianWorkload (mismas reglas que los
 * listados) y se actualizan en tiempo real (ver TechnicianCounters).
 */
class DashboardController extends Controller
{
    public function index(Request $request, TechnicianWorkload $workload, ParachuteService $parachute)
    {
        $user = $request->user();

        if ($user->role === 'admin' || $user->isStaff()) {
            return redirect('/admin');
        }

        return view('dashboard', [
            'counts' => $workload->counts($user),
            'nextOrders' => $workload->nextWorkOrders($user),
            'month' => $workload->monthVisits($user),
            'visitsToday' => $workload->visitsToday($user),
            'recentNotes' => $workload->recentDeliveryNotes($user),
            'parachuteDue' => $this->parachuteDue($parachute, $user),
            // Visita técnica: la abierta (paso final) y los trabajos tomados sin llegada (paso 2).
            'siteVisit' => SiteVisits::openFor($user),
            'awaitingArrival' => SiteVisits::awaitingArrival($user),
        ]);
    }

    /** Pruebas de paracaídas que le corresponde hacer (las mismas que su pestaña «Paracaídas»). */
    private function parachuteDue(ParachuteService $parachute, $user): array
    {
        $counts = $parachute->forTechnician($user)->countBy('status');
        $due = [
            'overdue' => (int) $counts->get(ParachuteService::OVERDUE, 0),
            'failed' => (int) $counts->get(ParachuteService::FAILED, 0),
            'upcoming' => (int) $counts->get(ParachuteService::UPCOMING, 0),
        ];

        return $due + ['total' => array_sum($due), 'urgent' => $due['overdue'] + $due['failed'] > 0];
    }
}
