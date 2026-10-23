<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\Report;
use App\Models\WorkOrder;
use App\Services\Interventions\InterventionService;
use App\Services\Reports\ReportPhotoService;
use App\Support\ElevatorComponents;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Intervenciones que abre el técnico desde Reportes (urgencias y trabajos no
 * programados). Toda la lógica está en InterventionService: acá solo se
 * valida el formulario y se responde. Después de abrirla, el técnico sigue
 * con el flujo de siempre de la orden: «Hacer remito y finalizar».
 */
class InterventionController extends Controller
{
    public function __construct(private InterventionService $interventions) {}

    private function authorizeUser(Company $company): void
    {
        $user = Auth::user();
        abort_unless((int) $user->company_id === (int) $company->id && InterventionService::canUse($user), 403);
    }

    /** Nueva intervención (urgencia o trabajo no programado): elige el edificio. */
    public function create(Company $company)
    {
        $this->authorizeUser($company);
        $user = Auth::user();
        $assigned = $user->buildings()->pluck('buildings.id')->flip();

        $buildings = $this->interventions->buildingsQuery($user)
            ->with('client:id,name')->orderBy('name')
            ->get(['id', 'name', 'address', 'locality', 'neighborhood', 'client_id', 'client_name', 'elevator_count', 'freight_elevator_count'])
            ->map(fn ($b) => [
                'id' => $b->id,
                'name' => $b->name,
                'address' => $b->address,
                'place' => collect([$b->locality, $b->neighborhood])->filter()->unique()->implode(' · '),
                'client' => $b->client?->name ?? $b->client_name,
                'units' => $b->unitLabels(),
                'mine' => $assigned->has($b->id),
            ])->sortByDesc('mine')->values();

        return view('interventions.create', [
            'company' => $company,
            'workOrder' => null,
            'buildings' => $buildings,
            'onCall' => $this->interventions->isOnCallNow($user),
            'token' => $this->token(),
        ]);
    }

    public function store(Request $request, Company $company)
    {
        $this->authorizeUser($company);
        $data = $this->validated($request, withBuilding: true);

        try {
            $order = $this->interventions->start(Auth::user(), $data, $request->file('photos', []), $data['submission_token']);
        } catch (ValidationException $e) {
            return $this->fail($request, $e->errors());
        }

        return $this->done($request, $company, $order, 'Intervención registrada. Cuando termines, hacé el remito y pedí la firma.');
    }

    /** Otro trabajo de la misma visita (la orden en curso del técnico). */
    public function createJob(Company $company, WorkOrder $workOrder)
    {
        $this->authorizeUser($company);
        abort_unless((int) $workOrder->company_id === (int) $company->id, 404);
        abort_unless($workOrder->acceptsJobsFrom(Auth::user()), 403, 'Ya no se pueden sumar trabajos a esta orden.');

        $workOrder->load('building');

        return view('interventions.create', [
            'company' => $company,
            'workOrder' => $workOrder,
            'buildings' => collect(),
            'onCall' => false,
            'token' => $this->token(),
        ]);
    }

    public function storeJob(Request $request, Company $company, WorkOrder $workOrder)
    {
        $this->authorizeUser($company);
        abort_unless((int) $workOrder->company_id === (int) $company->id, 404);
        $data = $this->validated($request, withBuilding: false);

        try {
            $this->interventions->addJob(Auth::user(), $workOrder, $data, $request->file('photos', []), $data['submission_token']);
        } catch (ValidationException $e) {
            return $this->fail($request, $e->errors());
        }

        return $this->done($request, $company, $workOrder, 'Trabajo agregado a esta visita. Va a aparecer en el mismo remito.');
    }

    private function validated(Request $request, bool $withBuilding): array
    {
        return $request->validate([
            ...($withBuilding ? ['building_id' => 'required|integer'] : []),
            'elevator_number' => 'required|string|max:255',
            'channel' => ['required', Rule::in(array_keys(Report::CHANNELS))],
            'kind' => ['required', Rule::in(array_keys(InterventionService::KINDS))],
            'component' => ['nullable', Rule::in(array_keys(ElevatorComponents::LIST))],
            'description' => 'required|string|min:5|max:5000',
            'submission_token' => 'required|uuid',
            ...ReportPhotoService::rules(),
        ], [
            'building_id.required' => 'Elegí el edificio.',
            'elevator_number.required' => 'Elegí el equipo.',
            'channel.required' => 'Indicá por dónde llegó el reclamo.',
            'kind.required' => 'Indicá si es una urgencia.',
            'description.required' => 'Contá qué problema te informaron o encontraste.',
            'description.min' => 'La descripción debe tener al menos 5 caracteres.',
            ...ReportPhotoService::messages(),
        ]);
    }

    private function token(): string
    {
        return Str::isUuid((string) old('submission_token')) ? (string) old('submission_token') : (string) Str::uuid();
    }

    private function fail(Request $request, array $errors)
    {
        return $request->expectsJson()
            ? response()->json(['message' => collect($errors)->flatten()->first(), 'errors' => $errors], 422)
            : back()->withErrors($errors)->withInput();
    }

    private function done(Request $request, Company $company, WorkOrder $order, string $message)
    {
        session()->flash('success', $message);
        $url = route('work-orders.show', ['company' => $company->slug, 'workOrder' => $order->id]);

        return $request->expectsJson() ? response()->json(['redirect' => $url]) : redirect($url);
    }
}
