<?php

namespace App\Services\Sales;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Lo que ingresa el visitante en la calculadora (datos distintos: un edificio
 * puede tener varios ascensores y un cliente varios edificios), más el estado
 * de sus datos y los servicios opcionales que elige.
 */
final class CalculatorInput
{
    public const MAX = 100000;

    public const DATA_STATES = [
        'tidy' => 'En una planilla ordenada',
        'mixed' => 'En varias planillas o desordenados',
        'paper' => 'En papel o sin registrar',
    ];

    public const OPTIONS = [
        'training' => 'Capacitación del equipo (oficina y técnicos)',
        'launch_support' => 'Acompañamiento durante la puesta en marcha',
    ];

    public function __construct(
        public readonly int $buildings,
        public readonly int $elevators,
        public readonly int $clients,
        public readonly int $technicians,
        public readonly string $dataState = 'tidy',
        /** @var list<string> */
        public readonly array $options = [],
    ) {}

    /** @throws ValidationException */
    public static function fromRequest(array $data): self
    {
        $v = Validator::make($data, [
            'edificios' => ['required', 'integer', 'min:1', 'max:'.self::MAX],
            'ascensores' => ['required', 'integer', 'min:1', 'max:'.self::MAX],
            'clientes' => ['required', 'integer', 'min:1', 'max:'.self::MAX],
            'tecnicos' => ['required', 'integer', 'min:1', 'max:'.self::MAX],
            'datos' => ['nullable', 'in:'.implode(',', array_keys(self::DATA_STATES))],
            'opcionales' => ['nullable', 'array'],
            'opcionales.*' => ['in:'.implode(',', array_keys(self::OPTIONS))],
        ], [
            '*.required' => 'Completá este dato (un número).',
            '*.integer' => 'Escribí un número entero.',
            '*.min' => 'Tiene que ser al menos 1.',
            '*.max' => 'Ese número es demasiado grande: pedí una propuesta personalizada.',
        ], ['edificios' => 'edificios', 'ascensores' => 'ascensores y montacargas', 'clientes' => 'clientes', 'tecnicos' => 'técnicos']);

        $d = $v->validate();

        if ((int) $d['ascensores'] < (int) $d['edificios']) {
            throw ValidationException::withMessages(['ascensores' => 'Cada edificio tiene al menos un ascensor o montacargas: revisá la cantidad.']);
        }

        return new self((int) $d['edificios'], (int) $d['ascensores'], (int) $d['clientes'], (int) $d['tecnicos'],
            $d['datos'] ?? 'tidy', array_values(array_unique($d['opcionales'] ?? [])));
    }

    public function toArray(): array
    {
        return ['edificios' => $this->buildings, 'ascensores' => $this->elevators, 'clientes' => $this->clients, 'tecnicos' => $this->technicians,
            'datos' => $this->dataState, 'opcionales' => $this->options];
    }
}
