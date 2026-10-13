<?php

namespace App\Filament\Widgets;

use App\Enums\PermissionArea as Area;
use App\Enums\PlanFeature;
use App\Filament\Widgets\Concerns\DashboardBlock;
use App\Services\Insights\DashboardSummary;
use App\Support\Plans\PlanGuard;
use Filament\Widgets\ChartWidget;

/** Presupuestos de los últimos 90 días por estado (área Presupuestos, plan con Presupuestos). */
class QuotesStatusChart extends ChartWidget
{
    use DashboardBlock;

    protected static ?int $sort = 3;

    protected ?string $heading = 'Presupuestos (últimos 90 días)';

    protected ?string $maxHeight = '280px';

    protected ?string $pollingInterval = null;

    public static function canView(): bool
    {
        $company = static::company();

        return $company !== null
            && PlanGuard::for($company)->allows(PlanFeature::Quotes)
            && static::gate()->allows(Area::Quotes);
    }

    public function getColumnSpan(): int|string|array
    {
        return WorkTrendChart::canView() ? ['default' => 'full', 'lg' => 1] : 'full';
    }

    public function getDescription(): ?string
    {
        return 'Creados en los últimos 90 días. No incluye borradores sin enviar ni anulados.';
    }

    public function rendering(): void
    {
        // Revocado: el bloque se retira sin calcular ni enviar datos.
        if (static::canView()) {
            parent::rendering();
        }
    }

    public function updateChartData(): void
    {
        if (static::canView()) {
            parent::updateChartData();
        }
    }

    protected function getType(): string
    {
        return 'doughnut';
    }

    protected function getData(): array
    {
        $counts = app(DashboardSummary::class)->quotesBreakdown(static::company(), auth()->user());

        if ($counts === null) {
            return [];
        }

        return [
            'labels' => ['Esperando respuesta', 'Aprobados', 'Rechazados', 'Vencidos'],
            'datasets' => [[
                'label' => 'Presupuestos',
                'data' => [$counts['awaiting'], $counts['approved'], $counts['rejected'], $counts['expired']],
                'backgroundColor' => ['#38bdf8', '#16a34a', '#ef4444', '#94a3b8'],
                'borderWidth' => 0,
            ]],
        ];
    }

    protected function getOptions(): array
    {
        return [
            // Misma altura en los dos gráficos (maxHeight), sin la proporción por ancho.
            'maintainAspectRatio' => false,
            'plugins' => ['legend' => ['position' => 'bottom']],
            'scales' => ['x' => ['display' => false], 'y' => ['display' => false]],
            'cutout' => '62%',
        ];
    }
}
