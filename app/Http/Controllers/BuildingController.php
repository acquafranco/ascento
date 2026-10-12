<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\DB;

use App\Models\Building;
use Illuminate\Http\Request;
use App\Models\BuildingVisit;
use App\Models\DeliveryNote;
use App\Services\Insights\MaintenanceAgenda;
use App\Services\Visits\VisitSchedule;

class BuildingController extends Controller
{
public function index(Request $request)
{
    $user = auth()->user();

    abort_unless($user->company_id, 403);

    // Mes y año validados (nunca se usan tal cual del query string).
    $month = (int) $request->get('month', now()->month);
    $month = $month >= 1 && $month <= 12 ? $month : now()->month;
    $year = (int) $request->get('year', now()->year);
    $year = $year >= 2000 && $year <= 2100 ? $year : now()->year;

    $buildings = Building::where('company_id', $user->company_id)
        ->whereHas('users', fn ($query) => $query->where('users.id', $user->id))
        ->when($request->search, function ($query, $search) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('address', 'like', "%{$search}%")
                    ->orWhere('province', 'like', "%{$search}%")
                    ->orWhere('municipality', 'like', "%{$search}%")
                    ->orWhere('locality', 'like', "%{$search}%")
                    ->orWhere('neighborhood', 'like', "%{$search}%")
                    ->orWhereHas('client', fn ($client) => $client->where('name', 'like', "%{$search}%"));
            });
        })
        ->with([
            'client',
            'users' => fn ($query) => $query->where('users.id', $user->id),
        ])
        ->orderBy('name')
        ->paginate(20)
        ->withQueryString();

    $visitsToday = BuildingVisit::where('user_id', $user->id)
        ->where('company_id', $user->company_id)
        ->whereDate('visited_at', today())
        ->where('status', 'done')
        ->count();

    // Estado y progreso de TODAS sus asignaciones (no solo la página) con
    // las MISMAS reglas que su inicio, la Agenda y los recordatorios
    // (VisitSchedule + MaintenanceAgenda::statusFor). Sin cargar los
    // edificios como modelos: una consulta de asignaciones y la de visitas.
    $assignments = DB::table('building_user')
        ->join('buildings', 'buildings.id', '=', 'building_user.building_id')
        ->where('buildings.company_id', $user->company_id)
        ->whereNull('buildings.deleted_at')
        ->where('building_user.user_id', $user->id)
        ->whereIn('building_user.type', VisitSchedule::TYPES)
        ->select('building_user.building_id', 'building_user.type', 'buildings.elevator_count', 'buildings.freight_elevator_count')
        ->get();

    $schedule = VisitSchedule::load($assignments->pluck('building_id'), $month, $year, (int) $user->company_id);

    $rows = $assignments->map(function ($a) use ($schedule, $month, $year) {
        $eval = $schedule->evaluate((int) $a->building_id, $a->type, $month, $year);

        return [
            'building_id' => (int) $a->building_id,
            'type' => $a->type,
            'assigned' => true,
            'status' => MaintenanceAgenda::statusFor($eval, true),
            'period' => $eval['period'],
            'visits' => $eval['visits'],
            'expected' => $eval['expected'],
            'done' => $eval['done'],
            'not_done' => $eval['not_done'],
            'pending' => $eval['pending'],
            'elevators' => (int) $a->elevator_count + (int) $a->freight_elevator_count,
        ];
    })->keyBy(fn ($row) => $row['building_id'].'|'.$row['type']);

    $progress = MaintenanceAgenda::progress($rows->values());

    // Máquinas (ascensores + montacargas): dato secundario. Por hacer = las
    // de edificios con visitas pendientes en el período.
    $machines = fn (?string $type, bool $pendingOnly = false) => (int) $rows
        ->when($type, fn ($r) => $r->where('type', $type))
        ->when($pendingOnly, fn ($r) => $r->where('pending', '>', 0))
        ->unique('building_id')->sum('elevators');
    $totalMachines = $machines(null);
    $maintenanceTotalMachines = $machines('maintenance');
    $maintenanceRemaining = $machines('maintenance', true);
    $inspectionTotalMachines = $machines('inspection');
    $inspectionRemaining = $machines('inspection', true);

    return view('buildings.index', compact(
        'buildings',
        'month',
        'year',
        'visitsToday',
        'totalMachines',
        'rows',
        'progress',
        'maintenanceTotalMachines',
        'maintenanceRemaining',
        'inspectionTotalMachines',
        'inspectionRemaining',
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
