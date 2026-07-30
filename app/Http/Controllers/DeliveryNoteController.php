<?php

namespace App\Http\Controllers;

use App\Models\Building;
use App\Models\WorkOrder;
use App\Models\BuildingVisit;
use App\Models\DeliveryNote;
use Illuminate\Http\Request;
use Barryvdh\DomPDF\Facade\Pdf;
use App\Models\Company;
use Illuminate\Support\Facades\DB;

class DeliveryNoteController extends Controller
{
   public function index(Request $request, Company $company)

{

    $user = auth()->user();

    $query = DeliveryNote::with([
    'building',
    'user',
    'workOrder',
    'buildingVisit',
    ])->where('company_id', $company->id);

    if (!$user->isAdmin()) {
        $query->where('user_id', $user->id);
    }

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

    $deliveryNotes = $query

        ->latest()

        ->get();

    return view(

        'delivery-notes.index',

        compact('deliveryNotes')

    );

}

public function show($company, DeliveryNote $deliveryNote)
        {
            $user = auth()->user();

            if ($deliveryNote->company_id !== $user->company_id) {
                abort(404);
            }

            abort_unless(

                $user->isAdmin() ||

                $deliveryNote->user_id === $user->id,

                403

            );

       $deliveryNote->load([
            'building',
            'user',
            'workOrder.participants',
            'buildingVisit.participants',
        ]);

        return view(
            'delivery-notes.show',
            compact('deliveryNote')
        );
    }

    public function createFromBuilding(Request $request, $company, Building $building)
    {
        if ($building->company_id !== auth()->user()->company_id) {
            abort(404);
        }

        return view(
            'delivery-notes.create',
            [
                'building' => $building,
                'workOrder' => null,
                'month' => $request->month ?? now()->month,
                'year' => $request->year ?? now()->year,
                'assignmentType' => $request->assignment_type,
            ]
        );
    }

    public function createFromWorkOrder(Request $request, $company, WorkOrder $workOrder)
    {
        if ($workOrder->company_id !== auth()->user()->company_id) {
            abort(404);
        }

        return view(
            'delivery-notes.create',
            [
                'building' => $workOrder->building,
                'workOrder' => $workOrder,
                'month' => $request->month ?? now()->month,
                'year' => $request->year ?? now()->year,
                'assignmentType' => $workOrder->type === 'inspection'
                    ? 'inspection'
                    : 'maintenance',
            ]
        );
    }

    public function store(Request $request)
{
    $request->validate(
        [
            'building_id' => 'required|exists:buildings,id',
            'work_order_id' => 'nullable|exists:work_orders,id',
            'description' => 'required|string',
            'month' => 'nullable|integer|min:1|max:12',
            'year' => 'nullable|integer',
            'elevator_quantity' => 'required|integer|min:0',
            'freight_elevator_quantity' => 'required|integer|min:0',
            'assignment_type' => 'required|in:maintenance,inspection,work_order',
            'signature_name' => 'required|string|max:255',
            'signature' => 'required|string|min:100',
            'client_signature' => 'nullable|string',
            'client_signature_name' => 'nullable|string|max:255',
            'participants' => 'nullable|array',
            'participants.*' => 'exists:users,id',
        ],
        [
            'description.required' => 'Debe escribir el trabajo realizado.',
            'signature.required' => 'Debe realizar la firma del técnico.',
            'signature_name.required' => 'Debe escribir el nombre del técnico.',
            'building_id.required' => 'No se encontró el edificio.',
            'assignment_type.required' => 'No se pudo identificar el tipo de trabajo.',
        ]
    );

    return DB::transaction(function () use ($request) {

        $building = Building::where('company_id', auth()->user()->company_id)
            ->where('id', $request->building_id)
            ->firstOrFail();

        $assignmentType = $request->filled('work_order_id')
        ? 'work_order'
        : $request->assignment_type;


       if (in_array($assignmentType, ['maintenance', 'inspection'])) {

            $existingDelivery = DeliveryNote::where('company_id', auth()->user()->company_id)
                ->where('building_id', $building->id)
                ->where('month', $request->month)
                ->where('year', $request->year)
                ->where('assignment_type', $assignmentType)
                ->exists();

            if ($existingDelivery) {
                return back()->withErrors([
                    'general' => 'Este remito ya fue generado.'
                ]);
            }
        }
        /*
        |--------------------------------------------------------------------------
        | TIPO DE VISITA
        |--------------------------------------------------------------------------
        */

            if ($request->filled('work_order_id')) {

            $visit = null;

            }else {

            // Mantenimiento / inspección mensual
            $visitType = 'fixed';
            $assignmentType = $request->assignment_type;


            $existingVisit = BuildingVisit::where('building_id', $building->id)
        ->where('visit_type', 'fixed')
        ->where('assignment_type', $assignmentType)
        ->where('month', $request->month)
        ->where('year', $request->year)
        ->exists();


            if ($existingVisit) {

                return back()
                    ->withErrors([
                        'month' =>
                        'Este edificio ya tiene un registro para este mes.'
                    ]);
            }
        }



        /*
        |--------------------------------------------------------------------------
        | CREAR VISITA
        |--------------------------------------------------------------------------
        */

    /*
    |--------------------------------------------------------------------------
    | CREAR / OBTENER VISITA
    |--------------------------------------------------------------------------
    */

    if ($request->filled('work_order_id')) {

        $visit = null;

    } else {

        if ($assignmentType === 'inspection') {

        $visit = BuildingVisit::firstOrCreate(
            [
                'company_id'      => auth()->user()->company_id,
                'building_id'     => $building->id,
                'visit_type'      => 'fixed',
                'assignment_type' => 'inspection',
                'month'           => $request->month,
                'year'            => $request->year,
                'user_id'         => auth()->id(),
            ],
            [
                'status'     => 'done',
                'visited_at' => now(),
                'source'     => 'building',
            ]
        );

    } else {

        $visit = BuildingVisit::firstOrCreate(
            [
                'company_id'      => auth()->user()->company_id,
                'building_id'     => $building->id,
                'visit_type'      => 'fixed',
                'assignment_type' => 'maintenance',
                'month'           => $request->month,
                'year'            => $request->year,
            ],
            [
                'user_id'    => auth()->id(),
                'status'     => 'done',
                'visited_at' => now(),
                'source'     => 'building',
            ]
        );

    }
    }

    $visit?->participants()->syncWithPivotValues(
        $request->participants ?? [auth()->id()],
        ['role' => 'participant']
    );
    $visit?->participants()->updateExistingPivot(auth()->id(), ['role' => 'creator']);

    /*
    |--------------------------------------------------------------------------
    | PARTICIPANTES (PREPARADO PARA building_visit_participants)
    |--------------------------------------------------------------------------
    |
    | Cuando exista la tabla building_visit_participants,
    | aquí se registrarán el creador y los participantes reales.
    | Ejemplo:
    | $visit->participants()->sync([...]);
    |
    */

    $deliveryNote = DeliveryNote::create([
        'company_id' => auth()->user()->company_id,
        'building_id' => $building->id,
        'building_visit_id' => $visit?->id,
        'user_id' => auth()->id(),
        'work_order_id' => $request->work_order_id,
        'assignment_type' => $request->filled('work_order_id') ? 'work_order' : $request->assignment_type,
        'description' => $request->description,
        'elevator_quantity' => $request->elevator_quantity,
        'freight_elevator_quantity' => $request->freight_elevator_quantity,
        'performed' => $request->boolean('performed'),
        'month' => $request->month,
        'year' => $request->year,
        'signature_name' => $request->signature_name,
        'signature' => $request->signature,
        'client_signature' => $request->client_signature,
        'client_signature_name' => $request->client_signature_name,
    ]);


    if ($request->filled('work_order_id')) {
        $workOrder = WorkOrder::where('company_id', auth()->user()->company_id)
            ->where('id', $request->work_order_id)
            ->firstOrFail();

        $finishedAt = now();

        $workOrder->update([
            'status' => 'completed',
            'finished_at' => $finishedAt,
        ]);

        $visit = BuildingVisit::create([
            'company_id' => $workOrder->company_id,
            'building_id' => $workOrder->building_id,
            'user_id' => auth()->id(),
            'source' => 'work_order',
            'visit_type' => 'work_order',
            'work_order_id' => $workOrder->id,
            'assignment_type' => 'work_order',
            'month' => $finishedAt->month,
            'year' => $finishedAt->year,
            'status' => 'done',
            'visited_at' => $finishedAt,
            'started_at' => $workOrder->started_at,
            'finished_at' => $finishedAt,
            'work_type' => $workOrder->type,
            'unit' => $workOrder->unit,
            'notes' => $workOrder->notes,
        ]);

        $participants = $request->participants ?? [auth()->id()];

        $visit->participants()->syncWithPivotValues(
            $participants,
            ['role' => 'participant']
        );

        $visit->participants()->updateExistingPivot(
            auth()->id(),
            ['role' => 'creator']
        );

        /*
        |--------------------------------------------------------------------------
        | PARTICIPANTES (PREPARADO PARA building_visit_participants)
        |--------------------------------------------------------------------------
        |
        | Cuando exista la tabla building_visit_participants,
        | aquí se sincronizarán los participantes del work order.
        | Ejemplo:
        | $visit->participants()->sync([...]);
        |
        */
        $deliveryNote->update([
            'building_visit_id' => $visit->id,
        ]);
    }

    return redirect()
        ->route('delivery-notes.index', [
            'company' => auth()->user()->company->slug,
        ])
        ->with('success', 'Remito generado correctamente.');
    });

}


    public function pdf(Company $company, DeliveryNote $deliveryNote)

    {

        $user = auth()->user();

        if ($deliveryNote->company_id !== $user->company_id) {
            abort(404);
        }

        abort_unless(

            $user->isAdmin() || $deliveryNote->user_id === $user->id,

            403

        );

        $deliveryNote->load([
            'building',
            'user',
            'workOrder.participants',
            'buildingVisit.participants',
        ]);

        return view('delivery-notes.show', compact('deliveryNote'));

    }
public function showPublic(
    Company $company,
    $token
)
{

    $deliveryNote = DeliveryNote::where(
        'public_token',
        $token
    )
    ->where(
        'company_id',
        $company->id
    )
    ->firstOrFail();


    $deliveryNote->load([
        'building',
        'user',
        'workOrder.participants',
        'buildingVisit.participants',
    ]);


    return view(
        'delivery-notes.show',
        compact('deliveryNote')
    );
}
}
?>
