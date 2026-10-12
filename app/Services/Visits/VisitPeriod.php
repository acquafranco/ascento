<?php

namespace App\Services\Visits;

use App\Models\BuildingVisitPlan;
use Carbon\CarbonImmutable;

/**
 * Período de visitas de un edificio y tipo de trabajo: desde qué mes hasta
 * qué mes (ambos primer día del mes) y cuántas visitas se esperan en él.
 * Mensual: un mes, 1 visita. Quincenal: un mes, 2 visitas. Cada N meses:
 * N meses, 1 visita.
 */
final class VisitPeriod
{
    public function __construct(
        public readonly string $type,
        public readonly CarbonImmutable $start,
        public readonly CarbonImmutable $end,
        public readonly int $expected,
        public readonly ?BuildingVisitPlan $plan,
    ) {}

    /** Identidad de la visita N del período (índice único con el edificio). */
    public function key(int $slot): string
    {
        return sprintf('%s:%s:%d', $this->type, $this->start->format('Y-m'), $slot);
    }

    public function isMultiMonth(): bool
    {
        return ! $this->start->equalTo($this->end);
    }

    public function contains(int $month, int $year): bool
    {
        $index = $year * 12 + $month;

        return $index >= self::index($this->start) && $index <= self::index($this->end);
    }

    public static function index(CarbonImmutable $month): int
    {
        return $month->year * 12 + $month->month;
    }

    public function frequencyLabel(): string
    {
        return $this->plan?->label() ?? 'Mensual';
    }

    /** "octubre 2026" o "oct. – dic. 2026". */
    public function label(): string
    {
        if (! $this->isMultiMonth()) {
            return $this->start->locale('es')->translatedFormat('F Y');
        }

        $from = $this->start->locale('es')->translatedFormat($this->start->year === $this->end->year ? 'F' : 'F Y');

        return $from.' – '.$this->end->locale('es')->translatedFormat('F Y');
    }
}
