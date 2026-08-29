<?php

namespace App\Http\Controllers;

use App\Models\WorkOrder;
use Illuminate\Http\Request;
use App\Models\DeliveryNote;
use App\Models\BuildingVisit;
use App\Models\Building;

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

        $workOrdersBase = WorkOrder::whereHas('users', function ($query) use ($user) {

            $query->where('users.id', $user->id);

        });


        /*
        |--------------------------------------------------------------------------
        | KPIs
        |--------------------------------------------------------------------------
        */


       $tasksToday = BuildingVisit::whereDate('visited_at', today())
    ->whereIn('assignment_type', ['maintenance', 'inspection'])
    ->where(function ($q) use ($user) {

        $q->whereHas('participants', function ($query) use ($user) {
            $query->where('users.id', $user->id);
        })

        ->orWhere(function ($old) use ($user) {

            $old->whereDoesntHave('participants')
                ->where('user_id', $user->id);

        });

    })
    ->distinct('building_id')
    ->count('building_id');


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


       $completed = WorkOrder::whereHas('users', function ($query) use ($user) {

            $query->where('users.id', $user->id);

        })
        ->where('status', 'completed')
        ->whereDate('finished_at', today())
        ->count();



        /*
        |--------------------------------------------------------------------------
        | EDIFICIOS
        |--------------------------------------------------------------------------
        */


        $totalBuildings = $user
            ->buildings()
            ->whereHas('visits', function ($query) {
                $query->whereDate('visited_at', today());
            })
            ->distinct('buildings.id')
            ->count('buildings.id');


        $totalCompanyBuildings = Building::where(
            'company_id',
            $user->company_id
        )->count();



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
        )
        ->whereDate('created_at', today())
        ->count();


        /*
        |--------------------------------------------------------------------------
        | DASHBOARD COUNTERS
        |--------------------------------------------------------------------------
        */

        $dashboardCounters = [
            'pending' => $pending,
            'in_progress' => $inProgress,
            'completed_today' => $completed,
            'tasks_today' => $tasksToday,
        ];


        return view('dashboard', [

            /*
            | KPIs diarios
            */
            'tasks_today' => $tasksToday,

            'pending' => $pending,

            'in_progress' => $inProgress,

            'completed_today' => $completed,

            /*
            | Datos informativos
            */
            'total_buildings' => $totalBuildings,

            'total_company_buildings' => $totalCompanyBuildings,

            'templates' => $templates,

            'deliveryNotes' => $deliveryNotes,

            'dashboardCounters' => $dashboardCounters,

        ]);
    }
}
