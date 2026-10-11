<?php

namespace App\Http\Controllers;

use App\Services\Technicians\TechnicianWorkload;
use Illuminate\Http\Request;

/**
 * Inicio de la app del técnico: qué sigue, qué tiene en curso y las visitas
 * del mes. Los números salen de TechnicianWorkload (mismas reglas que los
 * listados) y se actualizan en tiempo real (ver TechnicianCounters).
 */
class DashboardController extends Controller
{
    public function index(Request $request, TechnicianWorkload $workload)
    {
        $user = $request->user();

        if ($user->role === 'admin') {
            return redirect('/admin');
        }

        return view('dashboard', [
            'counts' => $workload->counts($user),
            'nextOrders' => $workload->nextWorkOrders($user),
            'month' => $workload->monthVisits($user),
            'visitsToday' => $workload->visitsToday($user),
            'recentNotes' => $workload->recentDeliveryNotes($user),
        ]);
    }
}
