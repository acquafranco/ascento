<?php

namespace App\Http\Controllers;

use App\Models\Building;
use App\Models\Company;
use App\Models\DeliveryNote;
use App\Services\Reports\InspectionReportService;
use App\Services\Reports\ReportPhotoService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Reporte de la inspección en la app del técnico: «Crear reporte» (la
 * primera vez) y «Ver reporte». Empresa, visita de inspección y asignación
 * se verifican en InspectionReportService (un id ajeno → 404).
 */
class InspectionReportController extends Controller
{
    public function show(Request $request, Company $company, int $visit, InspectionReportService $reports)
    {
        $visit = $reports->visit($company, $visit);
        $user = $request->user();

        abort_unless($reports->canWorkOn($user, $visit), 404);

        $report = $reports->forVisit($visit)?->load(['photos', 'user:id,name']);

        return view('inspection-reports.show', [
            'company' => $company,
            'visit' => $visit,
            'building' => Building::withoutGlobalScopes()->withTrashed()->findOrFail($visit->building_id),
            'deliveryNote' => DeliveryNote::withoutGlobalScopes()->where('building_visit_id', $visit->id)->first(['id', 'number', 'performed']),
            'report' => $report,
            'canEdit' => $reports->canEdit($user, $visit, $report),
            'photosEnabled' => $reports->photosEnabled($visit),
            'maxPhotos' => ReportPhotoService::MAX_PHOTOS,
            'editing' => $report === null || $request->boolean('edit'),
        ]);
    }

    public function save(Request $request, Company $company, int $visit, InspectionReportService $reports)
    {
        $visit = $reports->visit($company, $visit);

        $data = $request->validate([
            'observations' => ['required', 'string', 'min:5', 'max:5000'],
            ...ReportPhotoService::rules(),
        ], [
            'observations.required' => 'Escribí las observaciones de la inspección.',
            'observations.min' => 'Escribí las observaciones de la inspección (al menos 5 caracteres).',
            ...ReportPhotoService::messages(),
        ]);

        try {
            $reports->save($request->user(), $visit, $data['observations'], $request->file('photos', []));
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors())->withInput();
        }

        return redirect()->route('inspection-reports.show', ['company' => $company->slug, 'visit' => $visit->id])
            ->with('success', 'Reporte de inspección guardado.');
    }

    public function destroyPhoto(Request $request, Company $company, int $visit, int $photo, InspectionReportService $reports)
    {
        $visit = $reports->visit($company, $visit);

        try {
            $reports->removePhoto($request->user(), $visit, $photo);
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors());
        }

        return back()->with('success', 'Foto quitada del reporte.');
    }
}
