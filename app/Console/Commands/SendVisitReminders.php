<?php

namespace App\Console\Commands;

use App\Filament\Pages\Agenda;
use App\Models\Company;
use App\Models\User;
use App\Notifications\App\VisitsReminderNotification;
use App\Services\Notifications\Notifier;
use App\Services\Visits\VisitSchedule;
use Carbon\CarbonInterface;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Recordatorios de la agenda mensual (corre todos los días; cada aviso sale
 * una sola vez por mes gracias a la clave del evento):
 *
 * - Desde el día 20: a cada técnico, sus visitas sin remito cuyo período
 *   vence este mes (mensual: todas; quincenal: las 2; cada N meses: solo en
 *   el último mes del período). Mismas reglas que la Agenda (VisitSchedule).
 * - Días 1 a 5: lo que quedó sin remito del período que terminó el mes
 *   anterior, a cada técnico y un resumen a los admins (también por correo).
 *   Varios días por si el scheduler no corrió alguno.
 *
 * Sin usuario autenticado: todo se filtra explícitamente por empresa. Solo
 * empresas con acceso vigente y asignaciones actuales (un técnico al que le
 * quitaron el edificio deja de recibir avisos de ese edificio).
 */
class SendVisitReminders extends Command
{
    protected $signature = 'notifications:visits';

    protected $description = 'Recordatorios mensuales de mantenimientos e inspecciones';

    public const PENDING_FROM_DAY = 20;

    public const OVERDUE_UNTIL_DAY = 5;

    public function handle(Notifier $notifier): int
    {
        $today = now();
        $sent = 0;

        Company::where('is_active', true)->each(function (Company $company) use ($today, $notifier, &$sent) {
            if (! $company->hasActiveAccess()) {
                return;
            }

            if ($today->day >= self::PENDING_FROM_DAY) {
                foreach ($this->missingByTechnician($company, $today) as $userId => $count) {
                    if ($user = User::find($userId)) {
                        $sent += (int) (bool) $notifier->sendTo($user, new VisitsReminderNotification('pending', $today->format('Y-m'), $count, $this->technicianPath($company)), $company->id);
                    }
                }
            }

            if ($today->day <= self::OVERDUE_UNTIL_DAY) {
                $previous = $today->copy()->subMonthNoOverflow();
                $missing = $this->missingByTechnician($company, $previous);

                foreach ($missing as $userId => $count) {
                    if ($user = User::find($userId)) {
                        $sent += (int) (bool) $notifier->sendTo($user, new VisitsReminderNotification('overdue', $previous->format('Y-m'), $count, $this->technicianPath($company)), $company->id);
                    }
                }

                if ($total = $this->missingTotal($company, $previous)) {
                    $admins = User::where('company_id', $company->id)->where('role', 'admin')->where('is_super_admin', false)->get();
                    $path = parse_url(Agenda::getUrl(panel: 'ascensores_app'), PHP_URL_PATH).'?month='.$previous->format('Y-m');
                    $sent += count($notifier->send($admins, new VisitsReminderNotification('overdue_summary', $previous->format('Y-m'), $total, $path), $company->id));
                }
            }
        });

        $this->info("Avisos enviados: {$sent}");

        return self::SUCCESS;
    }

    private function technicianPath(Company $company): string
    {
        return route('buildings.index', ['company' => $company->slug], false);
    }

    /**
     * Visitas que faltan en asignaciones vigentes (técnico activo, edificio
     * activo) cuyo período TERMINA en ese mes, con las reglas de
     * VisitSchedule: mensual = ese mes; quincenal = las 2 del mes; cada N
     * meses = solo en el último mes del período (antes todavía hay tiempo).
     * Un remito "no realizado" no cuenta como hecho, pero tampoco se reclama
     * de nuevo (lo coordina la oficina, como siempre).
     *
     * @return Collection<int, object{user_id: int, building_id: int, type: string, missing: int}>
     */
    private function missing(Company $company, CarbonInterface $month)
    {
        $assignments = DB::table('building_user')
            ->join('buildings', 'buildings.id', '=', 'building_user.building_id')
            ->join('users', 'users.id', '=', 'building_user.user_id')
            ->where('buildings.company_id', $company->id)->whereNull('buildings.deleted_at')->where('buildings.is_active', true)
            // Sin equipos registrados no hay visitas que reclamar.
            ->whereRaw('(coalesce(buildings.elevator_count, 0) + coalesce(buildings.freight_elevator_count, 0)) > 0')
            ->where('users.company_id', $company->id)->whereNull('users.deleted_at')->where('users.role', 'technician')
            ->whereIn('building_user.type', VisitSchedule::TYPES)
            ->select('building_user.user_id', 'building_user.building_id', 'building_user.type')
            ->get();

        $schedule = VisitSchedule::load($assignments->pluck('building_id'), $month->month, $month->year, $company->id);

        return $assignments->map(function ($row) use ($schedule, $month) {
            $eval = $schedule->evaluate((int) $row->building_id, $row->type, $month->month, $month->year);
            $endsThisMonth = $eval['period']->end->year === $month->year && $eval['period']->end->month === $month->month;
            $row->missing = $endsThisMonth ? $eval['pending'] : 0;

            return $row;
        })->filter(fn ($row) => $row->missing > 0)->values();
    }

    /** @return array<int, int> técnico => visitas que faltan */
    private function missingByTechnician(Company $company, CarbonInterface $month): array
    {
        return $this->missing($company, $month)->groupBy('user_id')->map(fn ($rows) => (int) $rows->sum('missing'))->all();
    }

    /** Visitas sin remito, sin contar dos veces un edificio con dos técnicos. */
    private function missingTotal(Company $company, CarbonInterface $month): int
    {
        return (int) $this->missing($company, $month)->unique(fn ($r) => $r->building_id.'-'.$r->type)->sum('missing');
    }
}
