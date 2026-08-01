<?php

namespace App\Http\Controllers;

use App\Models\WorkOrder;
use App\Models\BuildingVisit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

use App\Services\WhatsAppService;

class WorkOrderController extends Controller
{

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

        if ($user->role !== 'admin') {

            $query->where(function ($q) use ($user) {

                $q->whereHas('participants', function ($query) use ($user) {

                    $query->where(
                        'users.id',
                        $user->id
                    );

                })
                ->orWhereDoesntHave('participants')
                ->whereHas('users', function ($query) use ($user) {

                    $query->where(
                        'users.id',
                        $user->id
                    );

                });

            });

        }


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

        DB::transaction(function () use (
            $workOrder,
            $user
        ) {


            $workOrder = WorkOrder::lockForUpdate()
                ->where('id', $workOrder->id)
                ->where('company_id', $user->company_id)
                ->firstOrFail();


            if(
                $workOrder->status === 'completed'
            ){

                return;

            }


            /*
            |--------------------------------------------------------------------------
            | AGREGAR TECNICO PARTICIPANTE
            |--------------------------------------------------------------------------
            */

            $workOrder->participants()
                ->syncWithoutDetaching([
                    $user->id => [
                        'role' => 'participant'
                    ]
                ]);


            $workOrder->update([

                'status' =>
                    'in_progress',

                'started_at' =>
                    $workOrder->started_at ?? now(),

            ]);


        });


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



        $finishedAt = now();



        /*
        |--------------------------------------------------------------------------
        | FINALIZAR ORDEN Y CREAR REGISTROS DE VISITAS EN TRANSACCIÓN
        |--------------------------------------------------------------------------
        */

        DB::transaction(function () use ($workOrder, $finishedAt) {

            $workOrder->update([

                'status'
                    =>
                    'completed',

                'finished_at'
                    =>
                    $finishedAt,

            ]);


            /*
            |--------------------------------------------------------------------------
            | CREAR REGISTRO DE TEMPLATE PARA LOS TECNICOS
            |--------------------------------------------------------------------------
            */

            $workOrder->load('participants');

            foreach($workOrder->participants as $technician){

                BuildingVisit::firstOrCreate([
                    'company_id' => $workOrder->company_id,
                    'work_order_id' => $workOrder->id,
                    'user_id' => $technician->id,
                ], [
                    'building_id' => $workOrder->building_id,
                    'source' => 'work_order',
                    'visit_type' => 'work_order',
                    'assignment_type' => 'work_order',
                    'month' => $finishedAt->month,
                    'year' => $finishedAt->year,
                    'visited_at' => $finishedAt,
                    'started_at' => $workOrder->started_at,
                    'finished_at' => $finishedAt,
                    'work_type' => $workOrder->type,
                    'unit' => $workOrder->unit,
                    'notes' => $workOrder->notes,
                ]);

            }

        });


        return back()
            ->with(
                'success',
                'Trabajo finalizado correctamente.'
            );

    }

}
