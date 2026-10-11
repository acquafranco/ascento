<?php

namespace App\Filament\Widgets;

use App\Models\Building;
use App\Models\DeliveryNote;
use App\Models\Quote;
use App\Models\User;
use App\Models\WorkOrder;
use App\Support\CompanyContext;
use App\Support\Realtime;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\DB;

/**
 * Números del inicio del admin. Se actualizan solos: cuando cambia una orden,
 * un remito o un presupuesto de la empresa llega "dashboard.counters" por el
 * canal privado del admin y el widget se vuelve a calcular. Respaldo por si
 * un evento se pierde (corte de conexión): cada 2 min con tiempo real y cada
 * 60 s sin él (venía cada 5 s).
 */
class AdminStats extends StatsOverviewWidget
{
    protected function getPollingInterval(): ?string
    {
        return Realtime::enabled() ? '120s' : '60s';
    }

    /** @return array<string, string> */
    protected function getListeners(): array
    {
        $user = auth()->user();

        return $user && Realtime::enabled()
            ? ['echo-private:App.Models.User.'.$user->id.',.dashboard.counters' => '$refresh']
            : [];
    }

    protected function getStats(): array
    {
        $trabajosPendientes = WorkOrder::where('status', 'pending')->count();
        $ingresadosHoy = WorkOrder::whereDate('created_at', today())->count();

        $remitosHoy = DeliveryNote::whereDate('created_at', today())->count();

        $presupuestosPendientes = Quote::open()->count(); // borradores y enviados vigentes

        $edificios = Building::count();

        $ascensores = Building::sum(
            DB::raw('elevator_count + freight_elevator_count')
        );

        $tecnicosOcupados = WorkOrder::where('status', 'in_progress')
            ->with(['users', 'participants'])
            ->get()
            ->flatMap(function ($workOrder) {

                return $workOrder->users

                    ->merge($workOrder->participants);

            })
            ->unique('id')
            ->count();

        $totalTecnicos = User::where('company_id', CompanyContext::currentId())
            ->whereNotIn('role', ['admin', 'client'])
            ->count();

        $tecnicosDisponibles = max(0, $totalTecnicos - $tecnicosOcupados);

        return [

            // Abiertas, sin importar el día en que se cargaron.
            Stat::make('Pendientes', $trabajosPendientes)
                ->description($ingresadosHoy === 1 ? '1 orden cargada hoy' : $ingresadosHoy.' órdenes cargadas hoy')
                ->color('warning')
                ->descriptionIcon('heroicon-m-clock'),

            // En curso ahora (antes: solo las modificadas hoy).
            Stat::make('En proceso', WorkOrder::where('status', 'in_progress')->count())
                ->description('En curso ahora')
                ->color('info')
                ->descriptionIcon('heroicon-m-wrench-screwdriver'),

            // Terminadas hoy (por la fecha de finalización, no la de edición).
            // Las "no realizadas" no cuentan como completadas.
            Stat::make('Completados', WorkOrder::where('status', 'completed')->whereDate('finished_at', today())->count())
                ->description('Terminados hoy')
                ->color('success')
                ->descriptionIcon('heroicon-m-check-circle'),

            Stat::make('Remitos de hoy', $remitosHoy)
                ->description(today()->format('d/m/Y'))
                ->descriptionIcon('heroicon-m-document-text')
                ->color('success'),

            Stat::make('Presupuestos pendientes', $presupuestosPendientes)
                ->description('Esperando respuesta')
                ->descriptionIcon('heroicon-m-currency-dollar')
                ->color('info'),

            Stat::make('Edificios', $edificios)
                ->description('Registrados')
                ->descriptionIcon('heroicon-m-building-office-2')
                ->color('primary'),

            Stat::make('Máquinas', $ascensores)
                ->description('Total instaladas')
                ->descriptionIcon('heroicon-m-arrows-up-down')
                ->color('gray'),

            Stat::make('Técnicos ocupados', $tecnicosOcupados)
                ->description('Actualmente en trabajo')
                ->descriptionIcon('heroicon-m-user-group')
                ->color('warning'),

            Stat::make('Técnicos disponibles', $tecnicosDisponibles)
                ->description('Sin trabajo activo')
                ->descriptionIcon('heroicon-m-user-group')
                ->color('success'),

        ];
    }
}
