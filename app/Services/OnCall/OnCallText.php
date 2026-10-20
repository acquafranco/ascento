<?php

namespace App\Services\OnCall;

use App\Models\OnCallSchedule;
use Carbon\CarbonInterface;

/**
 * Fechas y horas de guardias en palabras de todos los días:
 * «hoy 18:00», «mañana 08:00», «jue 23/10 18:00»,
 * «desde el lunes 20/10 a las 18:00 hasta el martes 21/10 a las 08:00».
 */
final class OnCallText
{
    /** «hoy 18:00», «mañana 08:00», «ayer 22:00» o «jue 23/10 18:00». */
    public static function short(CarbonInterface $at): string
    {
        $today = now()->startOfDay();
        $day = $at->copy()->startOfDay();

        $prefix = match (true) {
            $day->eq($today) => 'hoy',
            $day->eq($today->copy()->addDay()) => 'mañana',
            $day->eq($today->copy()->subDay()) => 'ayer',
            default => OnCallSchedule::SHORT_DAYS[$at->dayOfWeekIso].' '.$at->format('d/m'),
        };

        return $prefix.' '.$at->format('H:i');
    }

    /** «el lunes 20/10 a las 18:00» (con «hoy» o «mañana» si corresponde). */
    public static function long(CarbonInterface $at): string
    {
        $today = now()->startOfDay();
        $day = $at->copy()->startOfDay();
        $weekday = $at->translatedFormat('l d/m');

        $when = match (true) {
            $day->eq($today) => 'hoy '.$weekday,
            $day->eq($today->copy()->addDay()) => 'mañana '.$weekday,
            default => 'el '.$weekday,
        };

        return $when.' a las '.$at->format('H:i');
    }

    /** «desde el lunes 20/10 a las 18:00 hasta el martes 21/10 a las 08:00» (o «…, sin fecha de fin»). */
    public static function range(CarbonInterface $from, ?CarbonInterface $to): string
    {
        return 'desde '.self::long($from).($to ? ' hasta '.self::long($to) : ', sin fecha de fin');
    }

    /** «hoy 18:00 a 08:00» si termina al otro día a la mañana, o «hoy 18:00 a jue 23/10 18:00». */
    public static function span(CarbonInterface $from, CarbonInterface $to): string
    {
        $sameOrNext = $to->copy()->startOfDay()->diffInDays($from->copy()->startOfDay(), true) <= 1 && $to->diffInHours($from, true) < 24;

        return self::short($from).' a '.($sameOrNext ? $to->format('H:i') : self::short($to));
    }
}
