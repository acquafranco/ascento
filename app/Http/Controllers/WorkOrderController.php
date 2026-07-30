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
            'users',
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

            $query->whereHas(
                'users',
                function ($q) use ($user) {

                    $q->where(
                        'users.id',
                        $user->id
                    );

                }
            );

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

            $workOrder->users()
                ->syncWithoutDetaching([
                    $user->id
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
            !$workOrder->users()
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

            $workOrder->load('users');

            foreach($workOrder->users as $technician){

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
