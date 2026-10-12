<?php

namespace App\Filament\Pages;

use App\Enums\PermissionArea;
use App\Enums\PlanFeature;
use App\Filament\Concerns\RequiresPlanFeature;
use App\Models\Company;
use App\Models\User;
use App\Services\Insights\MaintenanceAgenda;
use App\Services\Visits\VisitService;
use App\Support\Access\Access;
use App\Support\CompanyContext;
use Carbon\Carbon;
use Filament\Pages\Page;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Url;
use Livewire\WithPagination;

/**
 * Agenda automática de mantenimientos e inspecciones: qué edificio toca cada
 * mes, quién lo tiene asignado y si ya se hizo. Sin calendario: una lista
 * ordenada por lo que requiere acción.
 */
class Agenda extends Page
{
    /** Área de permisos de administrativos (Etapa 6). */
    public static function permissionArea(): ?PermissionArea
    {
        return PermissionArea::Visits;
    }

    use RequiresPlanFeature;
    use WithPagination;

    /** Filas por página: el mes completo de una empresa grande eran cientos de filas de una vez. */
    public const PER_PAGE = 50;

    protected static PlanFeature $planFeature = PlanFeature::Agenda;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-calendar-days';

    protected static ?string $navigationLabel = 'Agenda';

    protected static string|\UnitEnum|null $navigationGroup = 'Operaciones';

    protected static ?int $navigationSort = 0;

    protected static ?string $slug = 'agenda';

    protected static ?string $title = 'Agenda de mantenimientos';

    protected string $view = 'filament.pages.agenda';

    #[Url]
    public string $month = '';

    #[Url]
    public string $type = '';

    #[Url]
    public string $technician = '';

    #[Url]
    public string $status = '';

    /** Cambiar un filtro vuelve a la primera página. */
    public function updated(string $property): void
    {
        if (in_array($property, ['month', 'type', 'technician', 'status'], true)) {
            $this->resetPage();
        }
    }

    public function mount(): void
    {
        // Sin mes en la URL: el actual (y el selector lo muestra así).
        $this->month = $this->period()->format('Y-m');
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();

        // Dueño y SuperAdmin, como siempre; un administrativo, con el área habilitada.
        return Access::can($user, PermissionArea::Visits);
    }

    public function getSubheading(): ?string
    {
        return 'Qué edificio toca este mes según su frecuencia de visitas, quién lo tiene asignado y si ya se hizo. Se actualiza sola cuando el técnico firma el remito.';
    }

    /** Período elegido (validado: nunca se confía en el query string). */
    public function period(): Carbon
    {
        if (preg_match('/^(\d{4})-(\d{2})$/', $this->month, $m) && checkdate((int) $m[2], 1, (int) $m[1])) {
            $period = Carbon::create((int) $m[1], (int) $m[2], 1);

            if ($period->between(now()->subYears(2), now()->addYear())) {
                return $period;
            }
        }

        return now()->startOfMonth();
    }

    /** Meses para elegir: 6 hacia atrás y 2 hacia adelante. */
    public function monthOptions(): array
    {
        return collect(range(-6, 2))->mapWithKeys(function (int $offset) {
            $date = now()->startOfMonth()->addMonthsNoOverflow($offset);

            return [$date->format('Y-m') => ucfirst($date->locale('es')->translatedFormat('F Y'))];
        })->all();
    }

    public function technicianOptions(): array
    {
        return CompanyContext::currentId()
            ? User::where('company_id', CompanyContext::currentId())->where('role', 'technician')->orderBy('name')->pluck('name', 'id')->all()
            : [];
    }

    protected function getViewData(): array
    {
        if (! CompanyContext::currentId()) {
            return ['rows' => collect(), 'summary' => [], 'period' => $this->period(), 'progress' => null, 'staleVisits' => collect()];
        }

        $period = $this->period();
        $type = array_key_exists($this->type, MaintenanceAgenda::TYPES) ? $this->type : null;
        $status = array_key_exists($this->status, MaintenanceAgenda::STATUSES) ? $this->status : null;
        $technician = array_key_exists((int) $this->technician, $this->technicianOptions()) ? (int) $this->technician : null;

        $agenda = app(MaintenanceAgenda::class);

        // Progreso con los filtros de tipo y técnico (no el de estado): mismas
        // reglas que la app del técnico (VisitSchedule).
        $unfiltered = $agenda->rows($period->month, $period->year, $type, $technician);
        $all = $status ? $unfiltered->where('status', $status)->values() : $unfiltered;
        $page = max(1, min($this->getPage(), (int) ceil(max(1, $all->count()) / self::PER_PAGE)));

        return [
            // Se calcula el mes completo (el resumen y el orden "primero lo urgente"
            // necesitan todo), pero se dibujan solo las filas de la página.
            'rows' => new LengthAwarePaginator($all->forPage($page, self::PER_PAGE)->values(), $all->count(), self::PER_PAGE, $page),
            'summary' => $agenda->summary($period->month, $period->year, $type),
            'period' => $period,
            'progress' => MaintenanceAgenda::progress($unfiltered),
            // Visitas en curso (sin remito): el admin de la empresa las abre en
            // la app para ver sus fotos o descartarlas (el SuperAdmin, no).
            'visitsSlug' => (auth()->user()?->isAdmin() || Access::isActiveStaff(auth()->user())) ? Company::whereKey(CompanyContext::currentId())->value('slug') : null,
            // Visitas en curso hace más de 7 días (de cualquier mes): se avisan, nunca se cierran solas.
            'staleVisits' => app(VisitService::class)->stale(CompanyContext::currentId()),
        ];
    }
}
