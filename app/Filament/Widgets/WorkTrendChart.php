<?php

namespace App\Filament\Widgets;

use App\Enums\PermissionArea as Area;
use App\Enums\PlanFeature;
use App\Filament\Widgets\Concerns\DashboardBlock;
use App\Services\Insights\DashboardSummary;
use App\Support\Plans\PlanGuard;
use Filament\Widgets\ChartWidget;

/**
 * Trabajo de los últimos 6 meses: la evolución de Indicadores (mismas
 * cuentas). Cada serie con sus áreas: una serie no permitida no está en los
 * datos del gráfico (ni en sus tooltips).
 */
class WorkTrendChart extends ChartWidget
{
    use DashboardBlock;

    protected static ?int $sort = 2;

    protected ?string $heading = 'Trabajo de los últimos 6 meses';

    protected ?string $maxHeight = '280px';

    // Cambia poco: sin actualización periódica (se recalcula al abrir el inicio).
    protected ?string $pollingInterval = null;

    /** Calculado una vez por pedido (datos y opciones lo usan). */
    private ?array $trend = null;

    private bool $trendLoaded = false;

    private function trend(): ?array
    {
        if (! $this->trendLoaded) {
            $this->trend = app(DashboardSummary::class)->workTrend(static::company(), auth()->user());
            $this->trendLoaded = true;
        }

        return $this->trend;
    }

    public static function canView(): bool
    {
        $company = static::company();

        return $company !== null
            && PlanGuard::for($company)->allows(PlanFeature::CompanyIndicators)
            && static::allowsAny(Area::Visits, Area::WorkOrders);
    }

    public function getColumnSpan(): int|string|array
    {
        // Solo, ocupa todo el ancho; con el de presupuestos, la mitad.
        return QuotesStatusChart::canView() ? ['default' => 'full', 'lg' => 1] : 'full';
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
        return 'bar';
    }

    protected function getData(): array
    {
        $trend = $this->trend();

        if ($trend === null) {
            return [];
        }

        $styles = [
            'completed' => ['label' => 'Órdenes terminadas', 'backgroundColor' => '#f59e0b', 'borderColor' => '#f59e0b', 'yAxisID' => 'y'],
            'claims' => ['label' => 'Reportes y reclamos', 'backgroundColor' => '#94a3b8', 'borderColor' => '#94a3b8', 'yAxisID' => 'y'],
            'maintenance' => ['type' => 'line', 'label' => 'Mantenimientos cumplidos (%)', 'borderColor' => '#16a34a', 'backgroundColor' => '#16a34a', 'tension' => 0.3, 'spanGaps' => true, 'yAxisID' => 'y1'],
        ];

        return [
            'labels' => $trend['labels'],
            'datasets' => collect($trend['series'])->map(fn (array $values, string $key) => [...$styles[$key], 'data' => $values])->values()->all(),
        ];
    }

    protected function getOptions(): array
    {
        $hasPercent = array_key_exists('maintenance', $this->trend()['series'] ?? []);

        return [
            // Más alto que el 2:1 de Chart.js: legible en el celular y parejo con la dona.
            'aspectRatio' => 1.4,
            'plugins' => ['legend' => ['position' => 'bottom']],
            'scales' => array_filter([
                'y' => ['beginAtZero' => true, 'ticks' => ['precision' => 0]],
                'y1' => $hasPercent ? ['position' => 'right', 'min' => 0, 'max' => 100, 'grid' => ['drawOnChartArea' => false]] : null,
            ]),
        ];
    }
}
