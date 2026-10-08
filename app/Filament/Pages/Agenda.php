<?php

namespace App\Filament\Pages;

use App\Enums\PlanFeature;
use App\Filament\Concerns\RequiresPlanFeature;
use App\Models\User;
use App\Services\Insights\MaintenanceAgenda;
use App\Support\CompanyContext;
use Carbon\Carbon;
use Filament\Pages\Page;
use Livewire\Attributes\Url;

/**
 * Agenda automática de mantenimientos e inspecciones: qué edificio toca cada
 * mes, quién lo tiene asignado y si ya se hizo. Sin calendario: una lista
 * ordenada por lo que requiere acción.
 */
class Agenda extends Page
{
    use RequiresPlanFeature;

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

    public function mount(): void
    {
        // Sin mes en la URL: el actual (y el selector lo muestra así).
        $this->month = $this->period()->format('Y-m');
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user !== null && ($user->isAdmin() || $user->isSuperAdmin());
    }

    public function getSubheading(): ?string
    {
        return 'Qué edificio toca este mes, quién lo tiene asignado y si ya se hizo. Se actualiza sola cuando el técnico firma el remito.';
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
            return ['rows' => collect(), 'summary' => [], 'period' => $this->period()];
        }

        $period = $this->period();
        $type = array_key_exists($this->type, MaintenanceAgenda::TYPES) ? $this->type : null;
        $status = array_key_exists($this->status, MaintenanceAgenda::STATUSES) ? $this->status : null;
        $technician = array_key_exists((int) $this->technician, $this->technicianOptions()) ? (int) $this->technician : null;

        $agenda = app(MaintenanceAgenda::class);

        return [
            'rows' => $agenda->rows($period->month, $period->year, $type, $technician, $status),
            'summary' => $agenda->summary($period->month, $period->year, $type),
            'period' => $period,
        ];
    }
}
