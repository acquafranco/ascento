<?php

namespace App\Http\Controllers;

use App\Models\Report;
use App\Services\Reports\ReportPhotoService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use App\Models\Building;
use App\Models\Company;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Notifications\NewReportNotification;
use App\Models\User;

class ReportController extends Controller
{


    public function index(Company $company)
{
    abort_unless(
        auth()->user()->company_id === $company->id,
        403
    );
    $reports = Report::with([
            'building',
            'user'
        ])
        ->where('company_id', $company->id)
        ->where('company_id', auth()->user()->company_id)
        ->where('user_id', auth()->id())
        ->latest()
        ->paginate(15);

    return view('reports.index', compact('reports', 'company'));
}


    public function create(Company $company)
    {
        abort_unless(
            auth()->user()->company_id === $company->id,
            403
        );

        // Cupo mensual de reportes del plan: se avisa ANTES de completar el formulario.
        $guard = \App\Support\Plans\PlanGuard::for($company);
        $limit = \App\Enums\PlanLimit::ReportsPerMonth;

        if (! $guard->canAdd($limit)) {
            \App\Jobs\NotifyCompanyAdmins::reportLimitReached($company);

            return view('reports.limit', [
                'company' => $company,
                'message' => $guard->limitReachedMessage($limit),
            ]);
        }

        $planUsage = $guard->limit($limit) !== null ? [
            'label' => $guard->usageLabel($limit),
            'warning' => $guard->warning($limit),
        ] : null;

        $buildings = Building::where(
            'company_id',
            $company->id
        )
        ->where('is_active',true)
        ->orderBy('name')
        // Solo lo que usa el buscador del formulario (se serializa a JSON
        // en la página).
        ->get(['id', 'name', 'address', 'elevator_count', 'freight_elevator_count']);


        return view(
            'reports.create',
            compact(
                'buildings',
                'company',
                'planUsage'
            )
        );

    }

    public function show(Company $company, Report $report)
    {
        abort_unless($report->company_id === $company->id, 403);
        abort_unless($report->company_id === auth()->user()->company_id, 403);
        abort_unless($report->user_id === Auth::id(), 403);

        $report->load([
            'building',
            'user',
            'photos',
        ]);

        return view('reports.show', compact('report', 'company'));
    }


    public function store(
        Request $request,
        Company $company
    )
    {
        abort_unless(
            auth()->user()->company_id === $company->id,
            403
        );

        // Antes de procesar la foto: ¿queda cupo de reportes este mes?
        if (! \App\Support\Plans\PlanGuard::for($company)->canAdd(\App\Enums\PlanLimit::ReportsPerMonth)) {
            \App\Jobs\NotifyCompanyAdmins::reportLimitReached($company);

            return redirect()
                ->route('reports.create', ['company' => $company->slug]);
        }

        $data = $request->validate([
            'building_id' => 'required|integer',
            'elevator_number' => 'required|string|max:255',
            'description' => 'required|string|min:5|max:5000',
            'priority' => 'required|in:baja,media,alta,critica',
            // Fotos opcionales (hasta 6). Solo imágenes: se decodifican y se
            // vuelven a codificar (ver ReportPhotoService).
            ...ReportPhotoService::rules(),
        ], [
            'building_id.required' => 'Tenés que seleccionar un edificio.',
            'elevator_number.required' => 'Tenés que seleccionar un equipo.',
            'description.required' => 'La descripción es obligatoria.',
            'description.min' => 'La descripción debe tener al menos 5 caracteres.',
            'priority.required' => 'Seleccioná una prioridad.',
            ...ReportPhotoService::messages(),
        ]);

        $building = Building::where('id', $data['building_id'])
            ->where('company_id', $company->id)
            ->first();

        if (! $building) {
            return back()
                ->withErrors([
                    'building_id' => 'El edificio seleccionado no pertenece a esta empresa.',
                ])
                ->withInput();
        }

        // El equipo tiene que existir en ese edificio (no se confía en el select).
        if (! in_array($data['elevator_number'], $building->unitLabels(), true)) {
            return back()
                ->withErrors(['elevator_number' => 'Elegí un equipo de este edificio.'])
                ->withInput();
        }

        // Reporte y fotos juntos: si una foto no se puede procesar, no se
        // crea nada (ni quedan archivos sueltos).
        try {
            $report = DB::transaction(function () use ($data, $company, $request) {
                $report = Report::create([
                    'building_id' => $data['building_id'],
                    'elevator_number' => $data['elevator_number'],
                    'description' => $data['description'],
                    'priority' => $data['priority'],
                    'company_id' => $company->id,
                    'user_id' => Auth::id(),
                ]);

                app(ReportPhotoService::class)->add($report, $request->file('photos', []));

                return $report;
            });
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors())->withInput();
        }

        $report->load('building');


        // Aviso a los admins de la empresa (campanita + push), después de
        // responder: el técnico no espera.
        \App\Jobs\NotifyCompanyAdmins::reportCreated($report);



        return redirect()
            ->route(
                'reports.index',
                [
                    'company'=>$company->slug
                ]
            )
            ->with(
                'success',
                'Reporte creado correctamente'
            );

    }


}
