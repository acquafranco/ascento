<?php

namespace App\Http\Controllers;

use App\Models\Building;
use Illuminate\Http\Request;
use App\Models\BuildingVisit;
use App\Models\DeliveryNote;

class BuildingController extends Controller
{
public function index(Request $request)
{
    $user = auth()->user();

    $month = $request->get('month', now()->month);
    $year = $request->get('year', now()->year);

    $buildings = Building::whereHas('users', function ($query) use ($user) {

    $query->where('users.id', $user->id);

})

->when($request->search, function ($query, $search) {

    $query->where(function ($q) use ($search) {

        $q->where('name', 'like', "%{$search}%")
          ->orWhere('address', 'like', "%{$search}%")
          ->orWhere('province', 'like', "%{$search}%")
          ->orWhere('municipality', 'like', "%{$search}%")
          ->orWhere('locality', 'like', "%{$search}%")
          ->orWhere('neighborhood', 'like', "%{$search}%")

          ->orWhereHas('client', function ($client) use ($search) {

              $client->where('name', 'like', "%{$search}%");

          });

    });

})
    ->with([
        'client',



        'users' => function ($query) use ($user) {
            $query->where('users.id', $user->id);
        },

        'visits' => function ($query) use ($month, $year) {

            $query
                ->where('visit_type', 'fixed')
                ->where('month', $month)
                ->where('year', $year);

        }

    ])

    ->orderBy('name')
    ->paginate(20)
    ->withQueryString();

    $visitsToday = BuildingVisit::where('user_id', $user->id)
        ->whereDate('visited_at', today())
        ->where('status', 'done')
        ->count();

   $totalMachines = Building::whereHas('users', function ($query) use ($user) {
        $query->where('users.id', $user->id);
    })
    ->get()
    ->sum(function ($building) {
        return $building->elevator_count + $building->freight_elevator_count;
    });

// =============================
// MÁQUINAS DE MANTENIMIENTO ASIGNADAS
// =============================

$maintenanceBuildings = Building::whereHas('users', function ($query) use ($user) {
        $query->where('users.id', $user->id)
              ->where('building_user.type', 'maintenance');
    })
    ->get();

$maintenanceTotalMachines = $maintenanceBuildings->sum(function ($building) {
    return $building->elevator_count + $building->freight_elevator_count;
});

// realizados
$maintenanceCompletedMachines = BuildingVisit::where('status', 'done')
    ->where(function ($q) {
        $q->where('assignment_type', 'maintenance')
          ->orWhere('work_type', 'maintenance');
    })
    ->whereHas('building.users', function ($q) use ($user) {
        $q->where('users.id', $user->id)
          ->where('building_user.type', 'maintenance');
    })
    ->where(function ($q) use ($month, $year) {
        $q->where(function ($q) use ($month, $year) {
            $q->whereMonth('visited_at', $month)
              ->whereYear('visited_at', $year);
        })->orWhere(function ($q) use ($month, $year) {
            $q->whereMonth('finished_at', $month)
              ->whereYear('finished_at', $year);
        });
    })
    ->with('building')
    ->get()
    ->unique('building_id')
    ->sum(function ($visit) {
        return ($visit->building?->elevator_count ?? 0)
            + ($visit->building?->freight_elevator_count ?? 0);
    });

$maintenanceRemaining = max(
    $maintenanceTotalMachines - $maintenanceCompletedMachines,
    0
);

// =============================
// MÁQUINAS DE INSPECCIÓN ASIGNADAS
// =============================

$inspectionBuildings = Building::whereHas('users', function ($query) use ($user) {
        $query->where('users.id', $user->id)
              ->where('building_user.type', 'inspection');
    })
    ->get();

$inspectionTotalMachines = $inspectionBuildings->sum(function ($building) {
    return $building->elevator_count + $building->freight_elevator_count;
});

// realizados
$inspectionCompletedMachines = BuildingVisit::where('status', 'done')
    ->where('user_id', $user->id)
    ->where('assignment_type', 'inspection')
    ->where(function ($q) use ($month, $year) {
        $q->whereMonth('visited_at', $month)
          ->whereYear('visited_at', $year);
    })
    ->with('building')
    ->get()
    ->unique('building_id')
    ->sum(function ($visit) {
        return ($visit->building?->elevator_count ?? 0)
            + ($visit->building?->freight_elevator_count ?? 0);
    });

$inspectionRemaining = max(
    $inspectionTotalMachines - $inspectionCompletedMachines,
    0
);

return view('buildings.index', compact(
    'buildings',
    'month',
    'year',
    'visitsToday',
    'totalMachines',
    'maintenanceRemaining',
    'inspectionRemaining',
    'maintenanceTotalMachines',
    'inspectionTotalMachines'
));

}

    public function show(Building $building)
    {
        /*
        |--------------------------------------------------------------------------
        | SEGURIDAD
        |--------------------------------------------------------------------------
        */

        abort_unless(

            auth()->user()
                ->buildings()
                ->where('buildings.id', $building->id)
                ->exists(),

            403
        );

        return view(
            'buildings.show',
            compact('building')
        );
    }

public function all(Request $request)
{
    $buildings = Building::with('client')
        ->where('company_id', auth()->user()->company_id)
        ->select([
            'id',
            'name',
            'address',
            'client_id',
            'client_name',
            'contact_person',
            'phone',
            'elevator_count',
            'freight_elevator_count',
            'notes',
        ])
        ->orderBy('name')
        ->paginate(20);

    return view('buildings.all', compact('buildings'));
}
}
