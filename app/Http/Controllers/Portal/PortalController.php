<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\Building;
use App\Models\DeliveryNote;
use App\Models\Elevator;
use App\Models\ElevatorDocument;
use App\Models\Quote;
use App\Models\Report;
use App\Models\ReportPhoto;
use App\Support\Portal\PortalAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Portal del cliente: solo lectura de lo que la empresa compartió, de los
 * edificios autorizados. Toda la autorización pasa por PortalAccess.
 */
class PortalController extends Controller
{
    public function home(Request $request)
    {
        $user = $request->user();
        $ids = PortalAccess::buildingIds($user);

        $buildings = Building::whereIn('id', $ids)->orderBy('name')
            ->withCount(['elevators' => fn ($q) => $q->where('is_active', true)])
            ->get();

        return view('portal.home', [
            'user' => $user,
            'company' => $user->company,
            'client' => $user->client,
            'buildings' => $buildings,
        ]);
    }

    public function building(Request $request, Building $building)
    {
        $user = $request->user();
        PortalAccess::ensureBuilding($user, $building);

        $elevators = Elevator::where('building_id', $building->id)->where('is_active', true)->orderBy('kind')->orderBy('id')->get();
        $shared = fn ($query) => $query->where('building_id', $building->id)->where('shared_with_client', true);

        return view('portal.building', [
            'user' => $user,
            'company' => $user->company,
            'building' => $building,
            'elevators' => $elevators,
            'deliveryNotes' => $shared(DeliveryNote::query())->with('user:id,name')->latest()->limit(100)->get(),
            'reports' => $shared(Report::query())->withCount('photos')->latest()->limit(100)->get(),
            'quotes' => $shared(Quote::query())->latest()->limit(50)->get(),
            'documents' => ElevatorDocument::whereIn('elevator_id', $elevators->pluck('id'))->where('shared_with_client', true)
                ->with('elevator:id,label')->latest()->get(),
        ]);
    }

    public function deliveryNote(Request $request, DeliveryNote $deliveryNote)
    {
        PortalAccess::ensureShared($request->user(), $deliveryNote, (int) $deliveryNote->building_id);

        $unscoped = fn ($q) => $q->withoutGlobalScopes();
        $deliveryNote->load(['building', 'user', 'workOrder' => $unscoped, 'workOrder.participants', 'workOrder.materials.stockItem', 'buildingVisit.participants']);

        return view('delivery-notes.show', ['deliveryNote' => $deliveryNote, 'portal' => true]);
    }

    public function report(Request $request, Report $report)
    {
        $user = $request->user();
        PortalAccess::ensureShared($user, $report, (int) $report->building_id);

        $report->load(['building', 'photos']);

        return view('portal.report', ['user' => $user, 'company' => $user->company, 'report' => $report]);
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

        $quote->load(['items', 'company', 'building', 'client']);

        return view('quotes.public', ['quote' => $quote, 'portal' => true]);
    }

    public function document(Request $request, ElevatorDocument $elevatorDocument): StreamedResponse
    {
        $elevator = Elevator::findOrFail($elevatorDocument->elevator_id);
        PortalAccess::ensureShared($request->user(), $elevatorDocument, (int) $elevator->building_id);
        abort_unless($elevatorDocument->hasSafePath() && Storage::disk('local')->exists($elevatorDocument->path), 404);

        return Storage::disk('local')->response($elevatorDocument->path, $elevatorDocument->original_name ?: basename($elevatorDocument->path), [
            'Content-Type' => $elevatorDocument->mime,
            'Cache-Control' => 'private, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox",
        ]);
    }
}
