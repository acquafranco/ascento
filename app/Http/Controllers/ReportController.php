<?php

namespace App\Http\Controllers;

use App\Enums\PlanLimit;
use App\Jobs\NotifyCompanyAdmins;
use App\Models\Building;
use App\Models\Company;
use App\Models\Report;
use App\Services\Reports\ReportPhotoService;
use App\Services\Reports\ReportVideoService;
use App\Support\ElevatorComponents;
use App\Support\Plans\PlanGuard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

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
            'user',
        ])
            ->where('company_id', $company->id)
            ->where('company_id', auth()->user()->company_id)
            ->where('user_id', auth()->id())
            // Los reportes de inspección se ven desde su inspección (Visitas).
            ->incidents()
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
        $guard = PlanGuard::for($company);
        $limit = PlanLimit::ReportsPerMonth;

        if (! $guard->canAdd($limit)) {
            NotifyCompanyAdmins::reportLimitReached($company);

            return view('reports.limit', [
                'company' => $company,
                'message' => $guard->limitReachedMessage($limit),
            ]);
        }

        $planUsage = $guard->limit($limit) !== null ? [
            'label' => $guard->usageLabel($limit),
            'warning' => $guard->warning($limit),
        ] : null;

        // Edificios activos de la empresa con lo necesario para reconocerlos
        // (dirección, localidad, barrio, cliente) y si están asignados al
        // técnico (aparecen primero). Solo esos campos se serializan a JSON.
        $assigned = auth()->user()->buildings()->pluck('buildings.id')->flip();

        $buildings = Building::where('company_id', $company->id)
            ->where('is_active', true)
            ->with('client:id,name')
            ->orderBy('name')
            ->get(['id', 'name', 'address', 'locality', 'neighborhood', 'client_id', 'client_name', 'elevator_count', 'freight_elevator_count'])
            ->map(fn (Building $b) => [
                'id' => $b->id,
                'name' => $b->name,
                'address' => $b->address,
                'place' => collect([$b->locality, $b->neighborhood])->filter()->unique()->implode(' · '),
                'client' => $b->client?->name ?? $b->client_name,
                'units' => $b->unitLabels(),
                'mine' => $assigned->has($b->id),
            ])
            ->sortByDesc('mine')
            ->values();

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
        // Un reporte de inspección se ve desde su inspección (y solo con el plan Empresa).
        abort_if($report->isInspection(), 404);

        $report->load([
            'building',
            'user',
            'photos',
            'video',
        ]);

        return view('reports.show', compact('report', 'company'));
    }

    public function store(
        Request $request,
        Company $company
    ) {
        abort_unless(
            auth()->user()->company_id === $company->id,
            403
        );

        // El formulario del técnico envía con XMLHttpRequest para mostrar el
        // progreso de la subida: los errores vuelven como JSON (422) y el
        // éxito como la URL a la que ir. Sin JavaScript, el flujo de siempre.
        $fail = fn (array $errors) => $request->expectsJson()
            ? response()->json(['message' => collect($errors)->flatten()->first(), 'errors' => $errors], 422)
            : back()->withErrors($errors)->withInput();

        // Antes de procesar la foto: ¿queda cupo de reportes este mes?
        if (! PlanGuard::for($company)->canAdd(PlanLimit::ReportsPerMonth)) {
            NotifyCompanyAdmins::reportLimitReached($company);

            $url = route('reports.create', ['company' => $company->slug]);

            return $request->expectsJson() ? response()->json(['redirect' => $url]) : redirect($url);
        }

        $data = $request->validate([
            'building_id' => 'required|integer',
            'elevator_number' => 'required|string|max:255',
            'description' => 'required|string|min:5|max:5000',
            'priority' => 'required|in:baja,media,alta,critica',
            'component' => ['nullable', Rule::in(array_keys(ElevatorComponents::LIST))],
            // Fotos opcionales (hasta 6). Solo imágenes: se decodifican y se
            // vuelven a codificar (ver ReportPhotoService).
            ...ReportPhotoService::rules(),
            ...ReportVideoService::rules(),
        ], [
            'building_id.required' => 'Tenés que seleccionar un edificio.',
            'elevator_number.required' => 'Tenés que seleccionar un equipo.',
            'description.required' => 'La descripción es obligatoria.',
            'description.min' => 'La descripción debe tener al menos 5 caracteres.',
            'priority.required' => 'Seleccioná una prioridad.',
            ...ReportPhotoService::messages(),
            ...ReportVideoService::messages(),
        ]);

        $building = Building::where('id', $data['building_id'])
            ->where('company_id', $company->id)
            ->where('is_active', true)
            ->first();

        if (! $building) {
            return $fail(['building_id' => ['El edificio seleccionado no pertenece a esta empresa.']]);
        }

        // El equipo tiene que existir en ese edificio (no se confía en el select).
        if (! in_array($data['elevator_number'], $building->unitLabels(), true)) {
            return $fail(['elevator_number' => ['Elegí un equipo de este edificio.']]);
        }

        // Reporte y fotos juntos: si una foto no se puede procesar, no se
        // crea nada (ni quedan archivos sueltos). El video se valida antes.
        try {
            if ($request->hasFile('video')) {
                app(ReportVideoService::class)->check($company, $request->file('video'));
            }

            $report = DB::transaction(function () use ($data, $company, $request) {
                $report = Report::create([
                    'building_id' => $data['building_id'],
                    'elevator_number' => $data['elevator_number'],
                    'description' => $data['description'],
                    'priority' => $data['priority'],
                    'component' => $data['component'] ?? null,
                    'company_id' => $company->id,
                    'user_id' => Auth::id(),
                ]);

                app(ReportPhotoService::class)->add($report, $request->file('photos', []));

                // Video (opcional): plan, cantidad, tamaño y formato se validan en el servicio.
                if ($request->hasFile('video')) {
                    app(ReportVideoService::class)->store($report, $request->file('video'), Auth::user());
                }

                return $report;
            });
        } catch (ValidationException $e) {
            return $fail($e->errors());
        }

        $report->load('building');

        // Aviso a los admins de la empresa (campanita + push), después de
        // responder: el técnico no espera.
        NotifyCompanyAdmins::reportCreated($report);

        session()->flash('success', 'Reporte creado correctamente');
        $url = route('reports.index', ['company' => $company->slug]);

        return $request->expectsJson() ? response()->json(['redirect' => $url]) : redirect($url);

    }
}
