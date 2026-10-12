<?php

namespace App\Http\Controllers;

use App\Models\Building;
use App\Models\BuildingVisit;
use App\Models\Company;
use App\Models\DeliveryNote;
use App\Models\WorkOrder;
use App\Services\Visits\PhotoTarget;
use App\Services\Visits\VisitPhotoService;
use App\Services\Visits\VisitSchedule;
use App\Services\Visits\VisitService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Visitas del técnico (app): la pantalla de UNA visita, desde donde se
 * cargan sus fotos antes, durante o después del trabajo, y el remito.
 *
 * - start: abre la visita en curso del período (o devuelve la abierta).
 * - show: la visita (en curso o con remito) con sus fotos.
 * - discard: descarta una visita en curso sin remito (con sus fotos).
 * - workOrder: las fotos de una orden de trabajo (antes o después del remito).
 *
 * Todo se verifica en el servidor: empresa, asignación y tipo habilitado.
 */
class VisitController extends Controller
{
    public function start(Request $request, Company $company, VisitService $visits)
    {
        $data = $request->validate([
            'building_id' => ['required', 'integer'],
            'assignment_type' => ['required', Rule::in(VisitSchedule::TYPES)],
            'month' => ['required', 'integer', 'between:1,12'],
            'year' => ['required', 'integer', 'between:2000,2100'],
        ]);

        $building = Building::where('company_id', $company->id)->findOrFail($data['building_id']);

        try {
            $visit = $visits->start($request->user(), $building, $data['assignment_type'], (int) $data['month'], (int) $data['year']);
        } catch (ValidationException $e) {
            if ($request->wantsJson()) {
                throw $e;
            }

            return back()->withErrors(['general' => collect($e->errors())->flatten()->first()]);
        }

        // Desde la tarjeta del edificio (primera foto): la visita en curso, sin cambiar de pantalla.
        if ($request->wantsJson()) {
            return response()->json([
                'visit_id' => $visit->id,
                'url' => route('visits.show', ['company' => $company->slug, 'visit' => $visit->id]),
            ]);
        }

        return redirect()->route('visits.show', ['company' => $company->slug, 'visit' => $visit->id]);
    }

    public function show(Request $request, Company $company, int $visit, VisitPhotoService $photos)
    {
        $visit = $this->visit($company, $visit);
        $target = PhotoTarget::forVisit($visit);

        return $this->page($request, $company, $target, $photos);
    }

    public function discard(Request $request, Company $company, int $visit, VisitService $visits)
    {
        $visit = $this->visit($company, $visit);

        try {
            $visits->discard($visit, $request->user());
        } catch (ValidationException $e) {
            return back()->withErrors(['general' => collect($e->errors())->flatten()->first()]);
        }

        return redirect()->route('buildings.index', ['company' => $company->slug, 'month' => $visit->month, 'year' => $visit->year])
            ->with('success', 'Visita descartada. No quedó ningún registro ni foto.');
    }

    public function workOrder(Request $request, Company $company, int $workOrder, VisitPhotoService $photos)
    {
        $workOrder = WorkOrder::withoutGlobalScopes()->where('company_id', $company->id)->whereNull('deleted_at')->findOrFail($workOrder);

        return $this->page($request, $company, PhotoTarget::forWorkOrder($workOrder), $photos);
    }

    private function page(Request $request, Company $company, PhotoTarget $target, VisitPhotoService $photos)
    {
        $user = $request->user();
        abort_unless($photos->canWorkOn($user, $target), 404);

        $building = Building::withoutGlobalScopes()->withTrashed()->findOrFail($target->buildingId);
        $note = $target->visit ? DeliveryNote::withoutGlobalScopes()->where('building_visit_id', $target->visit->id)->first(['id', 'number', 'performed']) : null;

        return view('visits.show', [
            'company' => $company,
            'target' => $target,
            'building' => $building,
            'visit' => $target->visit,
            'workOrder' => $target->workOrder,
            'deliveryNote' => $note,
            ...$photos->managerData($user, $target),
            'period' => $target->visit && $target->type !== 'work_order'
                ? VisitSchedule::load([$building->id], (int) $target->visit->month, (int) $target->visit->year, (int) $building->company_id)
                    ->period($building->id, $target->type, (int) $target->visit->month, (int) $target->visit->year)
                : null,
        ]);
    }

    /** Visita de ESTA empresa, también en curso (un id ajeno → 404). */
    private function visit(Company $company, int $id): BuildingVisit
    {
        return BuildingVisit::withoutGlobalScope('completed')->where('company_id', $company->id)->findOrFail($id);
    }
}
