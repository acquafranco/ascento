<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

/**
 * Guardia técnica: una cobertura fuera del horario habitual (días y horario),
 * con uno o más técnicos a la vez, asignados a mano o por rotación.
 *
 * Ventanas de cobertura: empiezan los días elegidos a `starts_time` y terminan a
 * `ends_time`; si `ends_time` es menor o igual al inicio, terminan al día
 * siguiente (vie 18:00 → sáb 08:00; «00:00» = medianoche). Inicio incluido, fin
 * excluido: a las 08:00 en punto ya no está cubierta. Horas en la zona horaria
 * de la aplicación (config app.timezone).
 */
class OnCallSchedule extends Model
{
    use BelongsToCompany;

    public const MANUAL = 'manual';

    public const ROTATION = 'rotation';

    public const DAYS = [1 => 'Lunes', 2 => 'Martes', 3 => 'Miércoles', 4 => 'Jueves', 5 => 'Viernes', 6 => 'Sábado', 7 => 'Domingo'];

    public const SHORT_DAYS = [1 => 'lun', 2 => 'mar', 3 => 'mié', 4 => 'jue', 5 => 'vie', 6 => 'sáb', 7 => 'dom'];

    protected $fillable = [
        'name', 'days', 'starts_time', 'ends_time', 'technicians_needed', 'mode',
        'rotation_days', 'rotation_starts_on', 'is_active', 'notes',
    ];

    protected $casts = [
        'days' => 'array',
        'technicians_needed' => 'integer',
        'rotation_days' => 'integer',
        'rotation_starts_on' => 'date',
        'is_active' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::creating(function (OnCallSchedule $schedule) {
            $schedule->created_by ??= auth()->id();
        });

        static::saving(function (OnCallSchedule $schedule) {
            $days = collect((array) $schedule->days)->map(fn ($d) => (int) $d)->filter(fn ($d) => $d >= 1 && $d <= 7)->unique()->sort()->values()->all();
            $schedule->days = $days;
            $schedule->technicians_needed = max(1, min(10, (int) ($schedule->technicians_needed ?: 1)));
            $schedule->starts_time = self::normalizeTime($schedule->starts_time);
            $schedule->ends_time = self::normalizeTime($schedule->ends_time);
        });
    }

    public function members(): HasMany
    {
        return $this->hasMany(OnCallMember::class)->orderBy('position');
    }

    public function shifts(): HasMany
    {
        return $this->hasMany(OnCallShift::class);
    }

    public function changes(): HasMany
    {
        return $this->hasMany(OnCallChange::class)->latest('created_at')->latest('id');
    }

    /** Órdenes de trabajo originadas durante algún turno de esta guardia. */
    public function workOrders(): HasManyThrough
    {
        return $this->hasManyThrough(WorkOrder::class, OnCallShift::class, 'on_call_schedule_id', 'on_call_shift_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by')->withTrashed();
    }

    public function isRotation(): bool
    {
        return $this->mode === self::ROTATION;
    }

    public static function normalizeTime(?string $time): string
    {
        return Carbon::createFromFormat('H:i', substr((string) ($time ?: '00:00'), 0, 5))->format('H:i:s');
    }

    /** ¿La ventana termina al día siguiente? (fin ≤ inicio; inicio = fin es una ventana de 24 h). */
    public function crossesMidnight(): bool
    {
        return substr($this->ends_time, 0, 5) <= substr($this->starts_time, 0, 5);
    }

    /** Ventana que empieza el día $date (aunque ese día no esté entre los cubiertos). */
    public function windowStartingOn(CarbonInterface $date): array
    {
        $start = Carbon::parse($date->format('Y-m-d').' '.$this->starts_time);
        $end = Carbon::parse($date->format('Y-m-d').' '.$this->ends_time);
        if ($this->crossesMidnight()) {
            $end->addDay();
        }

        return [$start, $end];
    }

    /**
     * Ventanas de cobertura que se cruzan con [$from, $to).
     *
     * @return list<array{0: Carbon, 1: Carbon}>
     */
    public function windowsBetween(CarbonInterface $from, CarbonInterface $to): array
    {
        $windows = [];
        $day = Carbon::parse($from)->startOfDay()->subDay();
        $last = Carbon::parse($to)->startOfDay();
        $days = array_map('intval', (array) $this->days);

        for (; $day->lte($last); $day->addDay()) {
            if (! in_array($day->dayOfWeekIso, $days, true)) {
                continue;
            }
            [$start, $end] = $this->windowStartingOn($day);
            if ($start->lt($to) && $end->gt($from)) {
                $windows[] = [$start, $end];
            }
        }

        return $windows;
    }

    /** ¿El horario de la guardia cubre este momento? */
    public function covers(CarbonInterface $at): bool
    {
        foreach ($this->windowsBetween($at, Carbon::parse($at)->addSecond()) as [$start, $end]) {
            if ($start->lte($at) && $end->gt($at)) {
                return true;
            }
        }

        return false;
    }

    /** Ventana de cobertura en curso, o null. */
    public function currentWindow(CarbonInterface $at): ?array
    {
        foreach ($this->windowsBetween($at, Carbon::parse($at)->addSecond()) as $window) {
            if ($window[0]->lte($at) && $window[1]->gt($at)) {
                return $window;
            }
        }

        return null;
    }

    /** Primera ventana que empieza en o después de $from (busca hasta 8 días). */
    public function nextWindow(CarbonInterface $from): ?array
    {
        foreach ($this->windowsBetween($from, Carbon::parse($from)->addDays(8)) as $window) {
            if ($window[0]->gte($from)) {
                return $window;
            }
        }

        return null;
    }

    /** «lun a vie · 18:00 a 08:00 (del día siguiente)». */
    public function coverageLabel(): string
    {
        $days = array_map('intval', (array) $this->days);
        $label = match (true) {
            $days === [1, 2, 3, 4, 5, 6, 7] => 'Todos los días',
            $days === [1, 2, 3, 4, 5] => 'lun a vie',
            $days === [6, 7] => 'sáb y dom',
            default => implode(', ', array_map(fn ($d) => self::SHORT_DAYS[$d], $days)),
        };
        $start = substr($this->starts_time, 0, 5);
        $end = substr($this->ends_time, 0, 5);
        $hours = $start === $end ? '24 h desde las '.$start : "{$start} a {$end}".($this->crossesMidnight() ? ($end === '00:00' ? ' (medianoche)' : ' (del día siguiente)') : '');

        return $label.' · '.$hours;
    }

    public function modeLabel(): string
    {
        return $this->isRotation()
            ? 'Rotativa (turnos de '.($this->rotation_days ?? 7).' '.(($this->rotation_days ?? 7) === 1 ? 'día' : 'días').')'
            : 'Asignación manual';
    }

    /** ¿Ya hubo turnos vigentes en el pasado? (entonces su horario no se cambia: el historial depende de él) */
    public function hasStartedShifts(): bool
    {
        return $this->shifts()->where('starts_at', '<=', now())->exists();
    }
}
