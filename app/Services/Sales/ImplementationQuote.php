<?php

namespace App\Services\Sales;

use App\Models\ImplementationRate;

/**
 * Precio del servicio de implementación inicial (Ascento carga los datos de
 * la empresa para que empiece a trabajar). Determinista: los mismos datos y
 * las mismas tarifas dan siempre el mismo resultado.
 *
 *   variable = (clientes − incluidos) × por cliente
 *            + (edificios − incluidos) × por edificio
 *            + (ascensores − incluidos) × por ascensor        (nunca negativo)
 *   implementación = base + variable × factor según el estado de los datos
 *   opcionales     = capacitación y/o acompañamiento (precio fijo cada uno)
 *   total inicial  = implementación + opcionales
 *
 * Cada edificio y cada ascensor se cuentan una sola vez; los técnicos no
 * suman (sus usuarios los carga la empresa). Sin tarifas publicadas:
 * «pendiente de confirmación» (no se inventa un importe). Si supera el tope:
 * propuesta personalizada.
 */
final class ImplementationQuote
{
    /**
     * @return array{status: string, currency: string, lines: list<array{label: string, amount: float}>, implementation: ?float, options: list<array{key: string, label: string, amount: ?float}>, options_total: ?float, total: ?float}
     */
    public function quote(CalculatorInput $in, ?ImplementationRate $rates = null): array
    {
        $r = $rates ?? ImplementationRate::current();
        $empty = ['currency' => $r->currency ?: 'ARS', 'lines' => [], 'implementation' => null, 'options' => [], 'options_total' => null, 'total' => null];

        if (! $r->isReady()) {
            return ['status' => 'pending'] + $empty;
        }

        $extra = fn (int $have, int $included) => max(0, $have - $included);
        $factor = (float) match ($in->dataState) {
            'mixed' => $r->factor_mixed,
            'paper' => $r->factor_paper,
            default => $r->factor_tidy,
        };

        $units = [
            ['clientes', $extra($in->clients, $r->included_clients), (float) $r->per_client],
            ['edificios', $extra($in->buildings, $r->included_buildings), (float) $r->per_building],
            ['ascensores y montacargas', $extra($in->elevators, $r->included_elevators), (float) $r->per_elevator],
        ];

        $lines = [['label' => 'Tarifa base'.$this->included($r), 'amount' => $this->money((float) $r->base_fee)]];
        $variable = 0.0;
        foreach ($units as [$label, $count, $price]) {
            if ($count > 0) {
                $amount = $this->money($count * $price);
                $variable += $amount;
                $lines[] = ['label' => "{$count} {$label} adicionales", 'amount' => $amount];
            }
        }

        $adjustment = $this->money($variable * $factor) - $variable;
        if ($adjustment != 0.0) {
            $lines[] = ['label' => 'Ajuste por el estado de los datos ('.CalculatorInput::DATA_STATES[$in->dataState].')', 'amount' => $this->money($adjustment)];
        }

        $implementation = $this->money((float) $r->base_fee + $variable + $adjustment);

        $options = [];
        foreach ($in->options as $key) {
            $fee = $key === 'training' ? $r->training_fee : $r->launch_support_fee;
            $options[] = ['key' => $key, 'label' => CalculatorInput::OPTIONS[$key], 'amount' => $fee === null ? null : $this->money((float) $fee)];
        }
        $pendingOption = collect($options)->contains(fn ($o) => $o['amount'] === null);
        $optionsTotal = $pendingOption ? null : $this->money(collect($options)->sum('amount'));
        $total = $optionsTotal === null ? null : $this->money($implementation + $optionsTotal);

        $status = $r->max_quote !== null && ($total ?? $implementation) > (float) $r->max_quote ? 'custom' : 'priced';

        return [
            'status' => $status,
            'currency' => $r->currency ?: 'ARS',
            'lines' => $status === 'custom' ? [] : $lines,
            'implementation' => $status === 'custom' ? null : $implementation,
            'options' => $options,
            'options_total' => $status === 'custom' ? null : $optionsTotal,
            'total' => $status === 'custom' ? null : $total,
        ];
    }

    private function included(ImplementationRate $r): string
    {
        $parts = array_filter([
            $r->included_clients ? $r->included_clients.' clientes' : null,
            $r->included_buildings ? $r->included_buildings.' edificios' : null,
            $r->included_elevators ? $r->included_elevators.' ascensores' : null,
        ]);

        return $parts ? ' (incluye hasta '.implode(', ', $parts).')' : '';
    }

    /** Pesos sin centavos, redondeado hacia arriba de manera estable. */
    private function money(float $value): float
    {
        return (float) ceil(round($value, 2));
    }

    public static function format(?float $amount, string $currency = 'ARS'): string
    {
        return $amount === null ? 'A confirmar' : ($currency === 'ARS' ? '$ ' : $currency.' ').number_format($amount, 0, ',', '.');
    }
}
