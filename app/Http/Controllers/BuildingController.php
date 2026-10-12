<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\DB;

use App\Models\Building;
use Illuminate\Http\Request;
use App\Models\BuildingVisit;
use App\Models\DeliveryNote;

class BuildingController extends Controller
{
public function index(Request $request)
{
    $user = auth()->user();

    abort_unless($user->company_id, 403);

    $month = $request->get('month', now()->month);
    $year = $request->get('year', now()->year);

    $buildings = Building::where('company_id', $user->company_id)->whereHas('users', function ($query) use ($user) {

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

        'visits' => function ($query) use ($month, $year, $user) {

            $query
                ->with('deliveryNote:id,building_visit_id,performed')
                ->where('company_id', $user->company_id)
                ->where('visit_type', 'fixed')
                ->where('month', $month)
                ->where('year', $year);

        }

    ])

    ->orderBy('name')
    ->paginate(20)
    ->withQueryString();

    $visitsToday = BuildingVisit::where('user_id', $user->id)
        ->where('company_id', $user->company_id)
        ->whereDate('visited_at', today())
        ->where('status', 'done')
        ->count();

    // Máquinas (ascensores + montacargas) asignadas, hechas y pendientes del
    // mes. Se suman en la base: antes se traían todos los edificios asignados
    // (tres veces) y las visitas con su edificio solo para sumar.
    $machines = 'COALESCE(SUM(elevator_count + freight_elevator_count), 0)';
    $assigned = fn (?string $type) => Building::where('company_id', $user->company_id)
        ->whereHas('users', fn ($q) => $q->where('users.id', $user->id)->when($type, fn ($q) => $q->where('building_user.type', $type)));
    // Hecho = hay una visita "done" de ese tipo en el mes (una por edificio,
    // aunque haya más de un remito).
    $done = fn (string $type) => $assigned($type)->whereHas('visits', fn ($v) => $v
        ->where('company_id', $user->company_id)
        ->where('status', 'done')
        ->where('assignment_type', $type)
        ->where('month', $month)
        ->where('year', $year));

    $totalMachines = (int) $assigned(null)->value(DB::raw($machines));

    $maintenanceTotalMachines = (int) $assigned('maintenance')->value(DB::raw($machines));
    $maintenanceRemaining = max($maintenanceTotalMachines - (int) $done('maintenance')->value(DB::raw($machines)), 0);

    $inspectionTotalMachines = (int) $assigned('inspection')->value(DB::raw($machines));
    $inspectionRemaining = max($inspectionTotalMachines - (int) $done('inspection')->value(DB::raw($machines)), 0);

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

public function all(Request $request)
{
    $user = auth()->user();

    // Búsqueda en el servidor (antes filtraba solo la página visible y no andaba).
    $search = trim((string) $request->query('search', ''));

    $buildings = Building::with('client')
        ->where('company_id', $user->company_id)
        ->when($search !== '', fn ($query) => $query->where(fn ($q) => $q
            ->where('name', 'like', "%{$search}%")
            ->orWhere('address', 'like', "%{$search}%")
            ->orWhere('locality', 'like', "%{$search}%")
            ->orWhere('neighborhood', 'like', "%{$search}%")
            ->orWhere('client_name', 'like', "%{$search}%")
            ->orWhereHas('client', fn ($c) => $c->where('name', 'like', "%{$search}%"))))
        ->select([
            'id',
            'name',
            'address',
            'locality',
            'neighborhood',
            'client_id',
            'client_name',
            'contact_person',
            'phone',
            'elevator_count',
            'freight_elevator_count',
            'notes',
        ])
        ->orderBy('name')
        ->paginate(20)
        ->withQueryString();

    return view('buildings.all', compact('buildings'));
}
}
