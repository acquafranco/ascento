<?php

namespace App\Http\Controllers;

use App\Models\BuildingVisit;
use Carbon\Carbon;

class TemplateController extends Controller
{
    public function index()
    {
        Carbon::setLocale('es');

        $month = request(
            'month',
            now()->month
        );

        $year = request(
            'year',
            now()->year
        );

        /*
        |--------------------------------------------------------------------------
        | VISITAS DEL MES
        |--------------------------------------------------------------------------
        */

        $visits = BuildingVisit::with([
            'building',
            'workOrder',
        ])
            ->where(
                'user_id',
                auth()->id()
            )
            ->whereNotNull(
                'visited_at'
            )
            ->whereMonth(
                'visited_at',
                $month
            )
            ->whereYear(
                'visited_at',
                $year
            )
            ->orderBy(
                'visited_at'
            )
            ->get();

        $weeks = [];

        /*
        |--------------------------------------------------------------------------
        | ARMAR SEMANAS
        |--------------------------------------------------------------------------
        */

        $current = Carbon::create(
            $year,
            $month,
            1
        )->startOfWeek(
            Carbon::MONDAY
        );

        $end = Carbon::create(
            $year,
            $month,
            1
        )
            ->endOfMonth()
            ->endOfWeek(
                Carbon::SUNDAY
            );

        while (
            $current->lte($end)
        ) {

            $weekStart =
                $current
                    ->copy()
                    ->startOfDay();

            // 🔥 ANTES ERA addDays(4)
            // AHORA SON 7 DÍAS
            $weekEnd =
                $current
                    ->copy()
                    ->addDays(6)
                    ->endOfDay();

            $weekVisits =
                $visits->filter(
                    function (
                        $visit
                    ) use (
                        $weekStart,
                        $weekEnd
                    ) {

                        return $visit
                            ->visited_at
                            ->between(
                                $weekStart,
                                $weekEnd
                            );
                    }
                );

            $weeks[] = [

                'start' =>
                    $weekStart,

                'end' =>
                    $weekEnd,

                'visits' =>
                    $weekVisits,
            ];

            $current->addWeek();
        }

        return view(
            'templates.index',
            compact(
                'weeks',
                'month',
                'year'
            )
        );
    }

    public function day($date)
{
    $date = Carbon::parse($date);

  $visits = BuildingVisit::with([
    'building',
    'user',
    'workOrder',
    'deliveryNote',
])
    ->where('user_id', auth()->id())
    ->whereDate('visited_at', $date)
    ->orderBy('started_at')
    ->get();

    return view(
        'templates.day',
        compact('visits', 'date')
    );
}
}
