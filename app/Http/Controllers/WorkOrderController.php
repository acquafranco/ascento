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

       $query->where(function ($q) use ($user) {

    $q->whereHas('participants', function ($query) use ($user) {

        $query->where('users.id', $user->id);

    })
    ->orWhereHas('users', function ($query) use ($user) {

        $query->where('users.id', $user->id);

    });

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
