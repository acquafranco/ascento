<?php

namespace App\Http\Controllers;

use App\Models\WorkOrder;
use App\Models\BuildingVisit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
class WorkOrderController extends Controller
{
   public function index(Request $request)
{
    $user = Auth::user();

    $query = WorkOrder::with([
        'building',
        'technician',
    ]);

    /*
    |--------------------------------------------------------------------------
    | FILTRO POR STATUS
    |--------------------------------------------------------------------------
    */

    if ($request->filled('status')) {

        $query->where(
            'status',
            $request->status
        );
    }

    /*
    |--------------------------------------------------------------------------
    | TECNICOS
    |--------------------------------------------------------------------------
    */

    if ($user->role !== 'admin') {

        // Pendientes → todos pueden verlas para tomarlas
        if ($request->status === 'pending') {

            // no filtrar por user_id
                $query->where('user_id', $user->id);


        }

        // En progreso → solo las propias
        elseif ($request->status === 'in_progress') {

            $query->where(
                'user_id',
                $user->id
            );
        }

        // Completadas → solo las propias
        elseif ($request->status === 'completed') {

            $query->where(
                'user_id',
                $user->id
            );
        }

        // Sin status → mostrar solo las propias
        else {

            $query->where(
                'user_id',
                $user->id
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | FILTRO FECHA
    |--------------------------------------------------------------------------
    */

    if ($request->filled('day')) {

        $query->whereDay(
            'created_at',
            $request->day
        );
    }

    if ($request->filled('month')) {

        $query->whereMonth(
            'created_at',
            $request->month
        );
    }

    if ($request->filled('year')) {

        $query->whereYear(
            'created_at',
            $request->year
        );
    }

    if ($request->today) {

        $query->whereDate(
            'created_at',
            today()
        );
    }

    $workOrders = $query
        ->latest()
        ->get();

    return view(
        'work-orders.index',
        compact('workOrders')
    );
}

    /*
    |--------------------------------------------------------------------------
    | TOMAR TRABAJO
    |--------------------------------------------------------------------------
    */
    public function start($company, WorkOrder $workOrder)
    {
        $user = Auth::user();

        DB::transaction(function () use ($workOrder, $user) {

            $workOrder = WorkOrder::lockForUpdate()->find($workOrder->id);

            if ($workOrder->status !== 'pending') {
                return;
            }

            $workOrder->update([
                'user_id' => $user->id,
                'status' => 'in_progress',
                'started_at' => now(),
            ]);

        });

        return redirect()
            ->route('work-orders.index', [
                'company' => $user->company->slug,
                'status' => 'in_progress',
            ])
            ->with('success', 'Trabajo tomado correctamente.');
    }

    /*
    |--------------------------------------------------------------------------
    | FINALIZAR
    |--------------------------------------------------------------------------
    */

    public function finish(

    Request $request,

    $company,

    WorkOrder $workOrder

) {

        $user = Auth::user();

        /*
        |--------------------------------------------------------------------------
        | SOLO EL DUEÑO O ADMIN
        |--------------------------------------------------------------------------
        */

        if (
            $workOrder->user_id !== $user->id
            &&
            $user->role !== 'admin'
        ) {
            abort(403);
        }

        $request->validate([

            'delivery_note' =>
                'nullable|string|max:255',

        ]);

        $finishedAt = now();

        /*
        |--------------------------------------------------------------------------
        | FINALIZAR WORK ORDER
        |--------------------------------------------------------------------------
        */

        $workOrder->update([

            'status' =>
                'completed',

            'finished_at' =>
                $finishedAt,

            'delivery_note' =>
                $request->delivery_note,

        ]);

        /*
        |--------------------------------------------------------------------------
        | TEMPLATE
        |--------------------------------------------------------------------------
        */

        BuildingVisit::create([

            'company_id' => auth()->user()->company_id,

            'building_id' => $workOrder->building_id,

            'user_id' => $user->id,

            'source' => 'work_order',

            'visit_type' => 'work_order',

            'work_order_id' => $workOrder->id,

            'assignment_type' => 'work_order',

            'status' => 'done',

            'month' => $finishedAt->month,

            'year' => $finishedAt->year,

            'visited_at' => $finishedAt,

            // ESTO FALTABA BIEN
            'started_at' => $workOrder->started_at,

            'finished_at' => $finishedAt,

            'work_type' => $workOrder->type,

            'delivery_note' => $request->delivery_note,

            'unit' => $workOrder->unit,

            'notes' => $workOrder->notes,

        ]);

        return back();
    }
}
