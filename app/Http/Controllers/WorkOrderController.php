<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\WorkOrder;
use App\Services\Visits\PhotoTarget;
use App\Services\Visits\VisitPhotoService;
use App\Models\SiteVisit;
use App\Services\WorkOrders\AttendanceTimes;
use App\Services\WorkOrders\SiteVisits;
use App\Services\WorkOrderService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class WorkOrderController extends Controller
{
    public function __construct(private WorkOrderService $workOrderService) {}

    /*
    |--------------------------------------------------------------------------
    | DETALLE (destino del push "Nueva orden de trabajo")
    |--------------------------------------------------------------------------
    |
    | La empresa ya está validada por el middleware y el binding con alcance
    | ({company}/work-orders/{workOrder}). Acá se valida que la orden sea del
    | técnico; si ya no lo es, o fue cancelada, se explica en vez de un error.
    */

    public function show($company, WorkOrder $workOrder)
    {
        $user = Auth::user();

        abort_unless((int) $workOrder->company_id === (int) $user->company_id, 404);

        if ($workOrder->trashed()) {
            return response()->view('work-orders.unavailable', [
                'title' => 'Esta orden fue cancelada',
                'message' => 'El administrador eliminó esta orden de trabajo. No tenés que hacer nada.',
            ], 410);
        }

        $workOrder->load(['building.client', 'users', 'participants', 'deliveryNote', 'jobs.photos', 'creator', 'arrivedBy', 'leftBy', 'callRecordedBy']);

        $isAssigned = $workOrder->users->contains($user->id)
            || $workOrder->participants->contains($user->id);

        if (! $isAssigned && ! $user->isAdmin()) {
            return response()->view('work-orders.unavailable', [
                'title' => 'Esta orden ya no está asignada a vos',
                'message' => 'El administrador se la asignó a otro técnico. Si creés que es un error, consultalo con él.',
            ], 403);
        }

        // «Fotos del trabajo» dentro de la orden: con las fotos habilitadas
        // para órdenes (botones de carga) o si ya tiene fotos (solo lectura).
        $photoManager = null;
        $photoService = app(VisitPhotoService::class);
        $target = PhotoTarget::forWorkOrder($workOrder);

        if ($photoService->canWorkOn($user, $target)) {
            $data = $photoService->managerData($user, $target);

            if ($data['canUpload'] || $data['photos']->isNotEmpty()) {
                $photoManager = [...$data, 'target' => $target, 'company' => Company::findOrFail($workOrder->company_id)];
            }
        }

        return view('work-orders.show', compact('workOrder', 'photoManager'));
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

        $query->where(function ($q) use ($user, $request) {
            if ($request->status === 'completed') {
                $q->whereHas('participants', function ($query) use ($user) {
                    $query->where('users.id', $user->id);
                });
            } else {
                $q->whereHas('users', function ($query) use ($user) {
                    $query->where('users.id', $user->id);
                });
            }
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

        // Paginado: con cientos de órdenes, dibujarlas todas trababa el celular.
        $workOrders = $query
            ->latest()
            ->paginate(20)
            ->withQueryString();

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
    ) {

        $user = Auth::user();

        if ($workOrder->company_id !== $user->company_id) {
            abort(404);
        }

        // Solo los técnicos asignados pueden tomar la orden (el remito
        // final también exige estar asignado).
        if (! $user->isAdmin() && ! $workOrder->users()->whereKey($user->id)->exists()) {
            abort(403, 'No estás asignado a esta orden de trabajo.');
        }

        $this->workOrderService->start($workOrder, $user);

        return redirect()
            ->route(
                'work-orders.index',
                [
                    'company' => $user->company->slug,
                    'status' => 'in_progress',
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
    ) {

        $user = Auth::user();

        if ($workOrder->company_id !== $user->company_id) {
            abort(404);
        }

        /*
        |--------------------------------------------------------------------------
        | VALIDAR PARTICIPACIÓN
        |--------------------------------------------------------------------------
        */

        if (
            ! $workOrder->participants()
                ->where(
                    'users.id',
                    $user->id
                )
                ->exists()
            &&
            $user->role !== 'admin'
        ) {

            abort(403);

        }

        // El técnico cierra la orden firmando el remito (queda el registro
        // firmado del trabajo). Sin remito no se completa.
        if (! $user->isAdmin() && ! $workOrder->deliveryNote()->exists()) {
            return redirect()
                ->route('delivery-notes.work-order', ['company' => $user->company->slug, 'workOrder' => $workOrder->id])
                ->withErrors(['general' => 'Para terminar la orden, completá y firmá el remito.']);
        }

        $this->workOrderService->finish($workOrder, $user);

        return back()
            ->with(
                'success',
                'Trabajo finalizado correctamente.'
            );

    }

    /*
    |--------------------------------------------------------------------------
    | TIEMPOS DE ATENCIÓN (llegada y salida del edificio)
    |--------------------------------------------------------------------------
    | Solo el técnico asignado, con la hora del servidor y una vez cada una
    | (AttendanceTimes). No cambian el estado de la orden ni el remito.
    */

    public function arrive($company, WorkOrder $workOrder)
    {
        return $this->attendance($workOrder, 'arrive');
    }

    public function leave($company, WorkOrder $workOrder)
    {
        return $this->attendance($workOrder, 'leave');
    }

    /**
     * Llegada y salida desde la orden. La llegada es la de la VISITA al
     * edificio (SiteVisits): la orden y las demás del técnico ahí entran en
     * ella. La salida finaliza la visita; solo una orden anterior a las
     * visitas (con llegada propia, sin visita) usa la salida por orden.
     */
    private function attendance(WorkOrder $workOrder, string $action)
    {
        $user = Auth::user();
        abort_unless((int) $workOrder->company_id === (int) $user->company_id, 404);
        $back = route('work-orders.show', ['company' => $user->company->slug, 'workOrder' => $workOrder->id]);

        try {
            if ($action === 'arrive') {
                if ($workOrder->arrived_at !== null && $workOrder->site_visit_id === null) {
                    return redirect()->to($back)->with('warning', 'La llegada ya estaba registrada: '.AttendanceTimes::time($workOrder->arrived_at).'.');
                }
                [$visit, $now] = app(SiteVisits::class)->arrive($user, $workOrder->building()->withTrashed()->firstOrFail(), $workOrder);
                $at = AttendanceTimes::time($visit->arrived_at);

                return redirect()->to($back)->with($now ? 'success' : 'warning', $now ? 'Llegada registrada: '.$at.'.' : 'La llegada ya estaba registrada: '.$at.'.');
            }

            if ($workOrder->site_visit_id !== null) {
                $visit = SiteVisit::withoutGlobalScopes()->where('company_id', $user->company_id)->findOrFail($workOrder->site_visit_id);
                [$visit, $now] = app(SiteVisits::class)->leave($user, $visit);
                $at = AttendanceTimes::time($visit->left_at);

                return redirect()->to($back)->with($now ? 'success' : 'warning', $now ? 'Visita finalizada. Salida registrada: '.$at.'.' : 'La salida ya estaba registrada: '.$at.'.');
            }

            [$order, $now] = app(AttendanceTimes::class)->leave($user, $workOrder);
        } catch (ValidationException $e) {
            return redirect()->to($back)->with('warning', collect($e->errors())->flatten()->first());
        }

        $at = AttendanceTimes::time($order->left_at);

        return redirect()->to($back)->with($now ? 'success' : 'warning', $now ? 'Salida registrada: '.$at.'.' : 'La salida ya estaba registrada: '.$at.'.');
    }
}
