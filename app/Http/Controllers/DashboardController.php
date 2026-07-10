<?php

namespace App\Http\Controllers;

use App\Models\WorkOrder;
use Illuminate\Http\Request;
use App\Models\DeliveryNote;

class DashboardController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        /*
        |--------------------------------------------------------------------------
        | ADMIN -> PANEL FILAMENT
        |--------------------------------------------------------------------------
        */

        if ($user->role === 'admin') {
            return redirect('/admin');
        }

        /*
        |--------------------------------------------------------------------------
        | SOLO DATOS DEL USUARIO LOGUEADO
        |--------------------------------------------------------------------------
        */

        $workOrdersBase = WorkOrder::where(
            'user_id',
            $user->id
        );

        /*
        |--------------------------------------------------------------------------
        | KPIs
        |--------------------------------------------------------------------------
        */

        $tasksToday = (clone $workOrdersBase)
            ->whereDate(
                'created_at',
                today()
            )
            ->count();

        $pending = (clone $workOrdersBase)
            ->where(
                'status',
                'pending'
            )
            ->count();

        $inProgress = (clone $workOrdersBase)
            ->where(
                'status',
                'in_progress'
            )
            ->count();

        $completed = (clone $workOrdersBase)
            ->where(
                'status',
                'completed'
            )
            ->count();

        /*
        |--------------------------------------------------------------------------
        | EDIFICIOS
        |--------------------------------------------------------------------------
        */

        $totalBuildings = $user
            ->buildings()
            ->distinct('buildings.id')
            ->count('buildings.id');

        /*
        |--------------------------------------------------------------------------
        | TEMPLATES
        |--------------------------------------------------------------------------
        */

        $templates = $user
            ->buildingVisits()
            ->count();

            $deliveryNotes = DeliveryNote::where(
                'user_id',
                $user->id
            )->count();

        return view('dashboard', [

            'tasks_today' => $tasksToday,
            'pending' => $pending,
            'in_progress' => $inProgress,
            'completed_today' => $completed,
            'total_buildings' => $totalBuildings,
            'templates' => $templates,
            'deliveryNotes' => $deliveryNotes,

        ]);
    }
}
