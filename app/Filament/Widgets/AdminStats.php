<?php

namespace App\Filament\Widgets;

use App\Models\Building;
use App\Models\DeliveryNote;
use App\Models\Quote;
use App\Models\WorkOrder;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\DB;

class AdminStats extends StatsOverviewWidget
{
    protected function getStats(): array
    {
        $trabajosPendientes = WorkOrder::where('status', 'pending')->count();

        $remitosHoy = DeliveryNote::whereDate('created_at', today())->count();

        $presupuestosPendientes = Quote::where('status', 'pending')->count();

        $edificios = Building::count();

        $ascensores = Building::sum(
            DB::raw('elevator_count + freight_elevator_count')
        );

        return [

            Stat::make(
            'Pendientes',
            WorkOrder::where('status', 'pending')
                ->whereDate('created_at', today())
                ->count()
        )
            ->description('Ingresados hoy')
            ->color('warning')
            ->descriptionIcon('heroicon-m-clock'),

        Stat::make(
            'En proceso',
            WorkOrder::where('status', 'in_progress')
                ->whereDate('updated_at', today())
                ->count()
        )
            ->description('Activos hoy')
            ->color('info')
            ->descriptionIcon('heroicon-m-wrench-screwdriver'),

        Stat::make(
            'Completados',
            WorkOrder::where('status', 'completed')
                ->whereDate('updated_at', today())
                ->count()
        )
            ->description('Ejecutados hoy')
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

            Stat::make('Ascensores', $ascensores)
                ->description('Total instalados')
                ->descriptionIcon('heroicon-m-arrows-up-down')
                ->color('gray'),

        ];
    }
}
