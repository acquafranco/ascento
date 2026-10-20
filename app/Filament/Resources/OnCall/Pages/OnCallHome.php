<?php

namespace App\Filament\Resources\OnCall\Pages;

use App\Enums\PlanFeature;
use App\Filament\Concerns\RequiresPlanFeature;
use App\Filament\Resources\OnCall\OnCallAbsences;
use App\Filament\Resources\OnCall\OnCallActions;
use App\Filament\Resources\OnCall\OnCallScheduleResource;
use App\Models\OnCallSchedule;
use App\Services\OnCall\OnCallBoard;
use App\Services\OnCall\OnCallService;
use App\Support\CompanyContext;
use Carbon\Carbon;
use Filament\Actions\Action;
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
 * Lo normal es una rotación: los técnicos se turnan siempre en el mismo orden
 * y el ciclo se repite solo («Así se turnan»). Arriba, lo de todos los días:
 * reemplazar, cargar una ausencia y, para algo puntual, asignar a mano. Lo
 * secundario (calendario, historial, turnos anteriores, horarios) va plegado.
 * Solo muestra: las acciones validan todo en OnCallService.
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

    /** Red de seguridad de la rotación (ver OnCallAbsences::topUp), una vez al abrir. */
    public function mount(): void
    {
        OnCallAbsences::topUp((int) CompanyContext::currentId());
    }

    /**
     * Con rotación, lo de todos los días es reemplazar o cargar una ausencia;
     * asignar a mano queda para una guardia puntual. Sin rotación, asignar es
     * lo principal.
     */
    protected function getHeaderActions(): array
    {
        if (! $this->hasRotation()) {
            return [
                OnCallActions::assignAny()->color('primary'),
                OnCallActions::replaceAny(),
            ];
        }

        return [
            OnCallActions::replaceAny()->color('primary'),
            OnCallAbsences::mark(),
            OnCallActions::assignAny()->label('Asignar guardia puntual')->color('gray'),
        ];
    }

    /** Acciones que se abren desde la tarjeta de la rotación. */
    public function clearAbsenceAction(): Action
    {
        return OnCallAbsences::clear();
    }

    private function hasRotation(): bool
    {
        return OnCallSchedule::query()->where('company_id', CompanyContext::currentId())->where('is_active', true)
            ->where('mode', OnCallSchedule::ROTATION)->exists();
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
            'rotations' => OnCallSchedule::query()->where('company_id', $companyId)->where('is_active', true)->where('mode', OnCallSchedule::ROTATION)
                ->with(['members.user' => fn ($q) => $q->withoutGlobalScopes()])->orderBy('name')->get(),
            'canMarkAbsence' => OnCallAbsences::members()->isNotEmpty(),
        ];
    }
}
