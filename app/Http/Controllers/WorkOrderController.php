<?php

namespace App\Http\Controllers;

use App\Models\WorkOrder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

use App\Services\WhatsAppService;
use App\Services\WorkOrderService;

class WorkOrderController extends Controller
{

    public function __construct(private WorkOrderService $workOrderService)
    {
    }

    /*
    |--------------------------------------------------------------------------
    | DETALLE (destino del push "Nueva orden de trabajo")
    |--------------------------------------------------------------------------
    |
    | La empresa ya está validada por el middleware y el binding con alcance
    | ({company}/work-orders/{workOrder}). Acá se valida que la orden sea del
    | técnico; si ya no lo es, o fue cancelada, se explica en vez de un error.
    */

    public function show($company, WorkOrder $workOrder)
    {
        $user = Auth::user();

        abort_unless((int) $workOrder->company_id === (int) $user->company_id, 404);

        if ($workOrder->trashed()) {
            return response()->view('work-orders.unavailable', [
                'title' => 'Esta orden fue cancelada',
                'message' => 'El administrador eliminó esta orden de trabajo. No tenés que hacer nada.',
            ], 410);
        }

        $workOrder->load(['building.client', 'users', 'participants', 'deliveryNote']);

        $isAssigned = $workOrder->users->contains($user->id)
            || $workOrder->participants->contains($user->id);

        if (! $isAssigned && ! $user->isAdmin()) {
            return response()->view('work-orders.unavailable', [
                'title' => 'Esta orden ya no está asignada a vos',
                'message' => 'El administrador se la asignó a otro técnico. Si creés que es un error, consultalo con él.',
            ], 403);
        }

        return view('work-orders.show', compact('workOrder'));
    }

    public function index(Request $request)
    {
        $user = Auth::user();

        $query = WorkOrder::with([
            'building',
            'users',
            'participants',
            'deliveryNote',
        ])->where('company_id', $user->company_id);


        /*
        |--------------------------------------------------------------------------
        | FILTRO STATUS
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

        $query->where(function ($q) use ($user, $request) {
            if ($request->status === 'completed') {
                $q->whereHas('participants', function ($query) use ($user) {
                    $query->where('users.id', $user->id);
                });
            } else {
                $q->whereHas('users', function ($query) use ($user) {
                    $query->where('users.id', $user->id);
                });
            }
        });

        /*
        |--------------------------------------------------------------------------
        | FECHAS
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

    public function start(
        $company,
        WorkOrder $workOrder
    )
    {

        $user = Auth::user();

        if ($workOrder->company_id !== $user->company_id) {
            abort(404);
        }

        // Solo los técnicos asignados pueden tomar la orden (el remito
        // final también exige estar asignado).
        if (! $user->isAdmin() && ! $workOrder->users()->whereKey($user->id)->exists()) {
            abort(403, 'No estás asignado a esta orden de trabajo.');
        }

        $this->workOrderService->start($workOrder, $user);


        return redirect()
            ->route(
                'work-orders.index',
                [
                    'company'=>$user->company->slug,
                    'status'=>'in_progress'
                ]
            )
            ->with(
                'success',
                'Trabajo tomado correctamente.'
            );

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
    )
    {

        $user = Auth::user();

        if ($workOrder->company_id !== $user->company_id) {
            abort(404);
        }



        /*
        |--------------------------------------------------------------------------
        | VALIDAR PARTICIPACIÓN
        |--------------------------------------------------------------------------
        */

        if(
            !$workOrder->participants()
                ->where(
                    'users.id',
                    $user->id
                )
                ->exists()
            &&
            $user->role !== 'admin'
        ){

            abort(403);

        }


        $this->workOrderService->finish($workOrder, $user);


        return back()
            ->with(
                'success',
                'Trabajo finalizado correctamente.'
            );

    }

}
