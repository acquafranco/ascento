<?php

namespace App\Filament\Resources\OnCall\Pages;

use App\Enums\PlanFeature;
use App\Filament\Concerns\RequiresPlanFeature;
use App\Filament\Resources\OnCall\OnCallActions;
use App\Filament\Resources\OnCall\OnCallScheduleResource;
use App\Models\OnCallSchedule;
use App\Services\OnCall\OnCallBoard;
use App\Services\OnCall\OnCallService;
use App\Support\CompanyContext;
use Carbon\Carbon;
use Filament\Resources\Pages\Page;
use Throwable;

/**
 * Portada de «Guardias». Responde, en este orden:
 *
 * 1. ¿Quién está de guardia ahora y hasta cuándo?
 * 2. ¿Quién sigue?
 * 3. ¿Qué falta cubrir o choca? (advertencias, con la acción para resolverlo)
 * 4. Los próximos turnos, en orden.
 *
 * Arriba, las dos acciones de siempre: «Asignar guardia» y «Reemplazar
 * técnico». Lo secundario (calendario completo, historial, turnos anteriores,
 * horarios y rotaciones) va plegado abajo, en «Configuración avanzada» lo que
 * es configuración. Solo muestra: las acciones validan todo en OnCallService.
 */
class OnCallHome extends Page
{
    use RequiresPlanFeature;

    protected static PlanFeature $planFeature = PlanFeature::OnCall;

    protected static string $resource = OnCallScheduleResource::class;

    protected string $view = 'filament.on-call.home';

    protected static ?string $title = 'Guardias';

    protected ?string $subheading = 'Quién cubre fuera del horario habitual: ahora, después y lo que falta.';

    /** «¿Quién está de guardia el…?» (Y-m-d\TH:i). */
    public ?string $at = null;

    public static function canAccess(array $parameters = []): bool
    {
        return OnCallScheduleResource::canViewAny();
    }

    public function getBreadcrumbs(): array
    {
        return [];
    }

    protected function getHeaderActions(): array
    {
        return [
            OnCallActions::assignAny()->color('primary'),
            OnCallActions::replaceAny(),
        ];
    }

    protected function getViewData(): array
    {
        $companyId = (int) CompanyContext::currentId();

        $at = null;
        if (filled($this->at)) {
            try {
                $at = Carbon::parse($this->at);
            } catch (Throwable) {
                $at = null;
            }
        }

        return app(OnCallBoard::class)->build($companyId) + [
            'query' => $at ? app(OnCallService::class)->onDutyAt($companyId, $at) : null,
            'queryAt' => $at,
            'canManage' => fn (?OnCallSchedule $s) => $s && OnCallScheduleResource::canEdit($s),
            'canCreate' => OnCallScheduleResource::canCreate(),
            'url' => fn (OnCallSchedule $s) => OnCallScheduleResource::getUrl('view', ['record' => $s]),
            'editUrl' => fn (OnCallSchedule $s) => OnCallScheduleResource::getUrl('edit', ['record' => $s]),
            'createUrl' => OnCallScheduleResource::getUrl('create'),
            'listUrl' => OnCallScheduleResource::getUrl('schedules'),
        ];
    }
}
