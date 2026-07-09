<?php

namespace App\Http\Controllers;

use App\Models\Building;
use App\Models\WorkOrder;
use App\Models\BuildingVisit;
use App\Models\DeliveryNote;
use Illuminate\Http\Request;
use Barryvdh\DomPDF\Facade\Pdf;

class DeliveryNoteController extends Controller
{
   public function index(Request $request)

{

        $query = DeliveryNote::with([

            'building',

            'user',

        ])

        ->where('user_id', auth()->id());

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

    public function show(DeliveryNote $deliveryNote)
        {
            $user = auth()->user();

            abort_unless(

                $user->isAdmin() ||

                $deliveryNote->user_id === $user->id,

                403

            );

        return view(
            'delivery-notes.show',
            compact('deliveryNote')
        );
    }

    public function createFromBuilding(Request $request, Building $building)
    {
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

    public function createFromWorkOrder(Request $request, WorkOrder $workOrder)
    {
        return view(
            'delivery-notes.create',
            [
                'building' => $workOrder->building,
                'workOrder' => $workOrder,
                'month' => $request->month ?? now()->month,
                'year' => $request->year ?? now()->year,
            ]
        );
    }

    public function store(Request $request)
    {
        $request->validate([
            'building_id' => 'required|exists:buildings,id',
            'work_order_id' => 'nullable|exists:work_orders,id',
            'description' => 'required|string',

            'month' => 'required|integer|min:1|max:12',
            'year' => 'required|integer',

            'elevator_quantity' => 'required|integer|min:0',
            'freight_elevator_quantity' => 'required|integer|min:0',
            'assignment_type' => 'required|in:maintenance,inspection',
            'signature_name' => 'required|string|max:255',
            'signature' => 'required|string|min:100',
            'client_signature' => 'nullable|string',
            'client_signature_name' => 'nullable|string|max:255',
        ]);

        $building = Building::findOrFail(
            $request->building_id
        );

        $assignmentType = $request->assignment_type;

        $existingVisit = BuildingVisit::where('building_id', $building->id)
             ->where('user_id', auth()->id())
            ->where('visit_type', 'fixed')
            ->where('month', $request->month)
            ->where('year', $request->year)
            ->where('assignment_type', $assignmentType)
            ->exists();

        if($existingVisit){

            return back()
                ->withErrors([
                    'month' =>
                    'Este edificio ya tiene un mantenimiento registrado para este mes.'
                ]);

        }

        $visit = BuildingVisit::firstOrCreate(

            [
                'building_id' => $building->id,
                'user_id' => auth()->id(),
                'visit_type' => 'fixed',
                'assignment_type' => $assignmentType,
                'month' => $request->month,
                'year' => $request->year,
            ],

            [
                'source' => 'building',
                'status' => $request->boolean('performed') ? 'done' : 'failed',
                'visited_at' => now(),
            ]


    );

        $deliveryNote = DeliveryNote::create([

            'building_id' => $building->id,

            'building_visit_id' => $visit->id,

            'user_id' => auth()->id(),

            'work_order_id' => $request->work_order_id,

            'description' => $request->description,

            'elevator_quantity' =>
                $request->elevator_quantity,

            'freight_elevator_quantity' =>
                $request->freight_elevator_quantity,

            'performed' =>
                $request->boolean('performed'),

            'month' =>
                $request->month,

            'year' =>
                $request->year,

            'signature_name' =>
                $request->signature_name,

            'signature' =>
                $request->signature,

            'client_signature' =>
                $request->client_signature,

            'client_signature_name' =>
                $request->client_signature_name,
        ]);

        if ($request->filled('work_order_id')) {

            WorkOrder::find(
                $request->work_order_id
            )?->update([
                'status' => 'completed',
                'finished_at' => now(),
            ]);
        }

        return redirect()
            ->route(
                'delivery-notes.show',
                $deliveryNote
            )
            ->with(
                'success',
                'Remito generado correctamente.'
            );
    }


    public function pdf(DeliveryNote $deliveryNote)
    {
        $user = auth()->user();

        abort_unless(
            $user->isAdmin() || $deliveryNote->user_id === $user->id,
            403
        );

        return view('delivery-notes.show', compact('deliveryNote'));
    }

    public function showPublic($id)
    {
        $deliveryNote = DeliveryNote::findOrFail($id);

        return view('delivery-notes.show', compact('deliveryNote'));
    }
}
?>
