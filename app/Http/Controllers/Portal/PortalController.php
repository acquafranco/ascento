<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\Building;
use App\Models\BuildingVisit;
use App\Models\DeliveryNote;
use App\Models\Elevator;
use App\Models\ElevatorDocument;
use App\Models\Quote;
use App\Models\Report;
use App\Models\ReportPhoto;
use App\Support\Portal\PortalAccess;
use App\Support\Portal\PortalDocuments;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Portal del cliente: solo lectura de lo que la empresa compartió, de los
 * edificios autorizados, de la empresa activa del portal. Toda la
 * autorización pasa por PortalAccess (y el middleware "portal").
 */
class PortalController extends Controller
{
    /** Edificios autorizados de la empresa activa. */
    private function buildingIds(Request $request): array
    {
        $user = $request->user();

        return PortalAccess::buildingIds($user, PortalAccess::currentCompanyId($user))->map(fn ($id) => (int) $id)->all();
    }

    private const KINDS = ['DeliveryNote' => 'delivery_note', 'Report' => 'report', 'Quote' => 'quote', 'ElevatorDocument' => 'document'];

    /** Registros compartidos cuyo aviso todavía no se leyó ("Nuevo"). */
    private function newKeys(Request $request): array
    {
        return $request->user()->unreadNotifications()->where('dedupe_key', 'like', 'shared:%')->pluck('dedupe_key')
            ->map(function (string $key) {
                [, $class, $id] = array_pad(explode(':', $key), 3, null);

                return (self::KINDS[$class] ?? $class).':'.$id;
            })->all();
    }

    /** Abrir un registro marca leído su aviso (deja de figurar como "Nuevo"). */
    private function markSeen(Request $request, Model $record): void
    {
        $request->user()->unreadNotifications()->where('dedupe_key', 'shared:'.class_basename($record).':'.$record->getKey())
            ->update(['read_at' => now()]);
    }

    public function home(Request $request)
    {
        $user = $request->user();
        $ids = $this->buildingIds($request);
        $companyId = PortalAccess::currentCompanyId($user);

        $buildings = Building::whereIn('id', $ids)->orderBy('name')
            ->withCount(['elevators' => fn ($q) => $q->where('is_active', true)])
            ->get();

        // Última visita de mantenimiento / inspección por edificio (una consulta).
        $lastVisits = BuildingVisit::whereIn('building_id', $ids)->where('visit_type', 'fixed')
            ->selectRaw('building_id, assignment_type, max(visited_at) as last_at')
            ->groupBy('building_id', 'assignment_type')->get()
            ->groupBy('building_id');

        return view('portal.home', [
            'user' => $user,
            'buildings' => $buildings,
            'lastVisits' => $lastVisits,
            'recent' => PortalDocuments::paginate($companyId, $ids, [], 5),
            'buildingNames' => $buildings->pluck('name', 'id'),
            'newKeys' => $this->newKeys($request),
        ]);
    }

    /** Centro de documentos: filtros y paginación en el servidor. */
    public function documents(Request $request)
    {
        $user = $request->user();
        $ids = $this->buildingIds($request);
        $companyId = PortalAccess::currentCompanyId($user);

        $filters = $request->validate([
            'type' => ['nullable', 'in:'.implode(',', array_keys(PortalDocuments::TYPES))],
            'building' => ['nullable', 'integer'],
            'status' => ['nullable', 'string', 'max:30'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'q' => ['nullable', 'string', 'max:80'],
            'sort' => ['nullable', 'in:asc,desc'],
        ]);

        $buildings = Building::whereIn('id', $ids)->orderBy('name')->get(['id', 'name']);

        return view('portal.documents', [
            'filters' => $filters,
            'documents' => PortalDocuments::paginate($companyId, $ids, $filters),
            'counts' => PortalDocuments::counts($companyId, $ids, array_diff_key($filters, ['type' => 1])),
            'buildings' => $buildings,
            'buildingNames' => $buildings->pluck('name', 'id'),
            'newKeys' => $this->newKeys($request),
        ]);
    }

    /** Mantenimientos o inspecciones (por separado), con su remito si fue compartido. */
    public function visits(Request $request, string $type)
    {
        abort_unless(in_array($type, ['maintenance', 'inspection'], true), 404);

        $ids = $this->buildingIds($request);
        $filters = $request->validate([
            'building' => ['nullable', 'integer'],
            'year' => ['nullable', 'integer', 'between:2000,2100'],
            'result' => ['nullable', 'in:done,not_done'],
        ]);
        $buildingFilter = isset($filters['building']) && in_array((int) $filters['building'], $ids, true) ? [(int) $filters['building']] : $ids;

        $visits = BuildingVisit::whereIn('building_id', $buildingFilter ?: [0])
            ->where('visit_type', 'fixed')->where('assignment_type', $type)
            ->when($filters['year'] ?? null, fn ($q, $year) => $q->where('year', $year))
            ->when(($filters['result'] ?? null) === 'done', fn ($q) => $q->whereHas('deliveryNote', fn ($n) => $n->where('performed', true)))
            ->when(($filters['result'] ?? null) === 'not_done', fn ($q) => $q->whereHas('deliveryNote', fn ($n) => $n->where('performed', false)))
            ->with(['deliveryNote:id,building_visit_id,number,performed,shared_with_client,elevator_quantity,freight_elevator_quantity,user_id', 'deliveryNote.user:id,name'])
            ->orderByDesc('year')->orderByDesc('month')->orderByDesc('id')
            ->paginate(20)->withQueryString();

        $buildings = Building::whereIn('id', $ids)->orderBy('name')->get(['id', 'name']);

        return view('portal.visits', [
            'type' => $type,
            'visits' => $visits,
            'filters' => $filters,
            'buildings' => $buildings,
            'buildingNames' => $buildings->pluck('name', 'id'),
            'years' => BuildingVisit::whereIn('building_id', $ids ?: [0])->where('visit_type', 'fixed')->where('assignment_type', $type)
                ->distinct()->orderByDesc('year')->pluck('year'),
        ]);
    }

    public function building(Request $request, Building $building)
    {
        $user = $request->user();
        PortalAccess::ensureBuilding($user, $building);
        $companyId = PortalAccess::currentCompanyId($user);

        $elevators = Elevator::where('building_id', $building->id)->where('is_active', true)->orderBy('kind')->orderBy('id')->get();

        return view('portal.building', [
            'building' => $building,
            'elevators' => $elevators,
            'recent' => PortalDocuments::paginate($companyId, [(int) $building->id], [], 8),
            'counts' => PortalDocuments::counts($companyId, [(int) $building->id], []),
            'lastVisits' => BuildingVisit::where('building_id', $building->id)->where('visit_type', 'fixed')
                ->with('deliveryNote:id,building_visit_id,performed')
                ->orderByDesc('year')->orderByDesc('month')->limit(6)->get(),
            'buildingNames' => [$building->id => $building->name],
            'newKeys' => $this->newKeys($request),
        ]);
    }

    /** Cambiar de empresa (solo una con acceso activo). */
    public function switchCompany(Request $request)
    {
        $data = $request->validate(['company' => ['required', 'integer']]);

        abort_unless(PortalAccess::switchTo($request->user(), (int) $data['company']), 404);

        return redirect()->route('portal.home');
    }

    public function deliveryNote(Request $request, DeliveryNote $deliveryNote)
    {
        PortalAccess::ensureShared($request->user(), $deliveryNote, (int) $deliveryNote->building_id);
        $this->markSeen($request, $deliveryNote);

        $unscoped = fn ($q) => $q->withoutGlobalScopes();
        $deliveryNote->load(['building', 'user', 'workOrder' => $unscoped, 'workOrder.participants', 'workOrder.materials.stockItem', 'buildingVisit.participants']);

        return view('delivery-notes.show', ['deliveryNote' => $deliveryNote, 'portal' => true]);
    }

    public function report(Request $request, Report $report)
    {
        PortalAccess::ensureShared($request->user(), $report, (int) $report->building_id);
        $this->markSeen($request, $report);

        $report->load(['building', 'photos']);

        return view('portal.report', ['report' => $report]);
    }

    public function reportPhoto(Request $request, Report $report, ReportPhoto $photo): StreamedResponse
    {
        PortalAccess::ensureShared($request->user(), $report, (int) $report->building_id);
        abort_unless((int) $photo->report_id === (int) $report->id, 404);

        $disk = $photo->disk();
        abort_unless($disk, 404);

        return Storage::disk($disk)->response($photo->path, null, [
            'Cache-Control' => 'private, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'",
        ]);
    }

    public function quote(Request $request, Quote $quote)
    {
        PortalAccess::ensureShared($request->user(), $quote, (int) $quote->building_id);
        $this->markSeen($request, $quote);

        $quote->load(['items', 'company', 'building', 'client']);

        return view('quotes.public', ['quote' => $quote, 'portal' => true]);
    }

    public function document(Request $request, ElevatorDocument $elevatorDocument): StreamedResponse
    {
        $elevator = Elevator::findOrFail($elevatorDocument->elevator_id);
        PortalAccess::ensureShared($request->user(), $elevatorDocument, (int) $elevator->building_id);
        abort_unless($elevatorDocument->hasSafePath() && Storage::disk('local')->exists($elevatorDocument->path), 404);
        $this->markSeen($request, $elevatorDocument);

        return Storage::disk('local')->response($elevatorDocument->path, $elevatorDocument->original_name ?: basename($elevatorDocument->path), [
            'Content-Type' => $elevatorDocument->mime,
            'Cache-Control' => 'private, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox",
        ]);
    }
}
