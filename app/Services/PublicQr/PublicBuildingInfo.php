<?php

namespace App\Services\PublicQr;

use App\Models\Building;
use App\Models\BuildingPublicPage;
use App\Models\Company;
use App\Models\DeliveryNote;
use App\Models\Elevator;
use App\Models\RepairComponent;
use App\Models\WorkOrder;
use App\Services\Parachute\ParachuteService;
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
 * - Información administrativa: componentes enviados a reparación (Etapa
 *   8.4), solo tipo y estado, si la empresa publica ese bloque.
 * - Observación pendiente: una prueba registrada como no satisfactoria, la
 *   próxima prueba de paracaídas prevista cuando ya corresponde avisarla
 *   (dentro de los días de aviso o vencida, sin prueba registrada), y
 *   trabajos pendientes (solo tipo y estado, nunca notas).
 * - Evaluación o certificación profesional: Ascento no la emite; se aclara.
 *
 * Nunca: datos del cliente o propietario, técnicos, residentes, firmas,
 * fotos, documentos, notas internas, montos ni contratos. Sin sesión: todo
 * se filtra explícitamente por la empresa del QR (sin scopes globales).
 */
class PublicBuildingInfo
{
    public function __construct(private ParachuteService $parachutes) {}

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

        // Última prueba y, si ya corresponde avisar (próxima o vencida, igual
        // que el recordatorio), la fecha prevista: la misma cuenta del panel.
        $tests = collect();
        if ($page->show_safety_tests && $elevators->isNotEmpty()) {
            $tests = $this->parachutes->overview($companyId, (int) $page->building_id)->keyBy(fn (array $row) => $row['elevator']->id);
            $dates->push(...$tests->pluck('last')->filter()->pluck('performed_on'));
        }

        // Componentes fuera de la empresa (Etapa 8.4): solo tipo y estado,
        // nunca proveedor, montos, falla ni notas.
        $repairs = collect();
        if ($page->show_repairs) {
            $repairs = RepairComponent::withoutGlobalScopes()->where('company_id', $companyId)->where('building_id', $page->building_id)
                ->whereIn('status', RepairComponent::AWAY)->orderBy('id')->get(['elevator_id', 'component_type', 'status', 'sent_at'])
                ->map(fn (RepairComponent $r) => [
                    'elevator_id' => $r->elevator_id,
                    'component' => RepairComponent::TYPES[$r->component_type] ?? 'Componente',
                    'status' => RepairComponent::STATUSES[$r->status] ?? $r->status,
                    // Desde cuándo está fuera (la fecha de envío), no la de una edición interna.
                    'at' => $r->sent_at,
                ]);
            $dates->push(...$repairs->pluck('at'));
        }
        $shownElevators = $page->show_elevators ? $elevators->pluck('id') : collect();

        $pending = [];
        if ($page->show_pending_work) {
            $pending = WorkOrder::withoutGlobalScopes()->where('company_id', $companyId)->where('building_id', $page->building_id)
                ->whereNull('deleted_at')->whereIn('status', ['pending', 'in_progress'])
                ->selectRaw('type, status, count(*) as total')->groupBy('type', 'status')->get()
                ->map(fn ($row) => ['type' => WorkOrderLabels::type((string) $row->type), 'status' => WorkOrderLabels::status((string) $row->status), 'count' => (int) $row->total])
                ->all();
        }

        $items = $page->show_elevators ? $elevators->map(function (Elevator $e) use ($page, $tests, $dates, $repairs) {
            $status = $page->show_status && $e->public_status ? Elevator::PUBLIC_STATUSES[$e->public_status] ?? null : null;
            if ($status) {
                $dates->push($e->public_status_updated_at);
            }
            $row = $tests->get($e->id);
            $test = $row['last'] ?? null;
            $due = $row && in_array($row['status'], [ParachuteService::UPCOMING, ParachuteService::OVERDUE], true);

            return [
                'label' => $e->label,
                'kind' => Elevator::KINDS[$e->kind] ?? null,
                'status' => $status,
                'status_at' => $status ? $e->public_status_updated_at : null,
                'test' => $test ? ['date' => $test->performed_on, 'ok' => $test->isSatisfactory()] : null,
                'next_test' => $due ? ['date' => $row['next_due'], 'overdue' => $row['status'] === ParachuteService::OVERDUE] : null,
                'repairs' => $repairs->where('elevator_id', $e->id)->values()->all(),
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
                'repairs' => (bool) $page->show_repairs,
                'contact' => (bool) $page->show_contact,
            ],
            'elevators' => $items,
            'last_maintenance' => $maintenance ? Carbon::parse($maintenance) : null,
            'last_inspection' => $inspection ? Carbon::parse($inspection) : null,
            'pending' => $pending,
            // Los de un equipo que no se muestra (o sin equipo) van en un bloque del edificio.
            'building_repairs' => $repairs->reject(fn ($r) => $r['elevator_id'] && $shownElevators->contains($r['elevator_id']))->values()->all(),
            'updated_at' => $dates->filter()->map(fn ($d) => $d instanceof CarbonInterface ? $d : Carbon::parse($d))->max(),
        ];
    }
}
