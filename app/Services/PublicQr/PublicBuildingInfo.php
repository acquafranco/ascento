<?php

namespace App\Services\PublicQr;

use App\Models\Building;
use App\Models\BuildingPublicPage;
use App\Models\Company;
use App\Models\DeliveryNote;
use App\Models\Elevator;
use App\Models\ParachuteTest;
use App\Models\WorkOrder;
use App\Support\WorkOrderLabels;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * Lo que muestra el QR público de un edificio (Etapa 7). Lista CERRADA de
 * datos, cada uno con su categoría para que nadie lo confunda con una
 * certificación:
 *
 * - Información administrativa: empresa, edificio (nombre y dirección),
 *   ascensores (identificación) y el estado que la empresa informa.
 * - Trabajo realizado: fecha del último mantenimiento con remito realizado.
 * - Inspección registrada: fecha de la última inspección con remito
 *   realizado y de la última prueba de paracaídas registrada.
 * - Observación pendiente: una prueba registrada como no satisfactoria, y
 *   trabajos pendientes (solo tipo y estado, nunca notas).
 * - Evaluación o certificación profesional: Ascento no la emite; se aclara.
 *
 * Nunca: datos del cliente o propietario, técnicos, residentes, firmas,
 * fotos, documentos, notas internas, montos ni contratos. Sin sesión: todo
 * se filtra explícitamente por la empresa del QR (sin scopes globales).
 */
class PublicBuildingInfo
{
    public function for(BuildingPublicPage $page): array
    {
        $companyId = (int) $page->company_id;
        $company = Company::find($companyId);
        $building = Building::withoutGlobalScopes()->where('company_id', $companyId)->find($page->building_id);

        $elevators = Elevator::withoutGlobalScopes()->where('company_id', $companyId)->where('building_id', $page->building_id)
            ->where('is_active', true)->orderBy('label')->get(['id', 'label', 'kind', 'public_status', 'public_status_updated_at']);

        $dates = collect([$page->updated_at]);
        $notes = fn () => DeliveryNote::withoutGlobalScopes()->where('company_id', $companyId)->where('building_id', $page->building_id)->where('performed', true);

        $maintenance = null;
        if ($page->show_last_maintenance) {
            $maintenance = $notes()->where('assignment_type', 'maintenance')->max('created_at');
            $dates->push($maintenance);
        }

        $inspection = null;
        if ($page->show_last_inspection) {
            $inspection = $notes()->where(fn ($q) => $q->where('assignment_type', 'inspection')
                ->orWhere(fn ($o) => $o->where('assignment_type', 'work_order')
                    ->whereIn('work_order_id', WorkOrder::withoutGlobalScopes()->where('company_id', $companyId)->where('type', 'inspection')->select('id'))))
                ->max('created_at');
            $dates->push($inspection);
        }

        $tests = collect();
        if ($page->show_safety_tests && $elevators->isNotEmpty()) {
            $tests = ParachuteTest::withoutGlobalScopes()->where('company_id', $companyId)->whereIn('elevator_id', $elevators->pluck('id'))
                ->orderByDesc('performed_on')->orderByDesc('id')->get(['elevator_id', 'performed_on', 'result'])
                ->groupBy('elevator_id')->map->first();
            $dates->push(...$tests->pluck('performed_on'));
        }

        $pending = [];
        if ($page->show_pending_work) {
            $pending = WorkOrder::withoutGlobalScopes()->where('company_id', $companyId)->where('building_id', $page->building_id)
                ->whereNull('deleted_at')->whereIn('status', ['pending', 'in_progress'])
                ->selectRaw('type, status, count(*) as total')->groupBy('type', 'status')->get()
                ->map(fn ($row) => ['type' => WorkOrderLabels::type((string) $row->type), 'status' => WorkOrderLabels::status((string) $row->status), 'count' => (int) $row->total])
                ->all();
        }

        $items = $page->show_elevators ? $elevators->map(function (Elevator $e) use ($page, $tests, $dates) {
            $status = $page->show_status && $e->public_status ? Elevator::PUBLIC_STATUSES[$e->public_status] ?? null : null;
            if ($status) {
                $dates->push($e->public_status_updated_at);
            }
            $test = $tests->get($e->id);

            return [
                'label' => $e->label,
                'kind' => Elevator::KINDS[$e->kind] ?? null,
                'status' => $status,
                'status_at' => $status ? $e->public_status_updated_at : null,
                'test' => $test ? ['date' => $test->performed_on, 'ok' => $test->isSatisfactory()] : null,
            ];
        })->all() : [];

        $logo = $company?->logo && Storage::disk('public')->exists($company->logo) ? Storage::disk('public')->url($company->logo) : null;

        return [
            'company' => [
                'name' => $company?->name,
                'logo' => $logo,
                'phone' => $page->show_contact ? ($company?->phone ?: null) : null,
                'email' => $page->show_contact ? ($company?->email ?: null) : null,
                // Solo para el panel (aviso si se habilita el contacto sin datos); no se muestra en la página pública.
                'has_contact' => filled($company?->phone) || filled($company?->email),
            ],
            'building' => [
                'name' => $building?->name,
                'address' => trim(collect([$building?->address, $building?->locality])->filter()->implode(', ')),
            ],
            'show' => [
                'elevators' => (bool) $page->show_elevators,
                'maintenance' => (bool) $page->show_last_maintenance,
                'inspection' => (bool) $page->show_last_inspection,
                'tests' => (bool) $page->show_safety_tests && (bool) $page->show_elevators,
                'pending' => (bool) $page->show_pending_work,
                'contact' => (bool) $page->show_contact,
            ],
            'elevators' => $items,
            'last_maintenance' => $maintenance ? Carbon::parse($maintenance) : null,
            'last_inspection' => $inspection ? Carbon::parse($inspection) : null,
            'pending' => $pending,
            'updated_at' => $dates->filter()->map(fn ($d) => $d instanceof CarbonInterface ? $d : Carbon::parse($d))->max(),
        ];
    }
}
