<?php

namespace App\Http\Controllers;

use App\Models\BuildingVisit;
use Carbon\Carbon;
use Illuminate\Http\Request;
use App\Models\User;

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
            'user',
        ])
            ->whereHas('building.users', fn($q) => $q->where('users.id', auth()->id()))
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

   public function day(Request $request, $company, $date)
{
    $date = Carbon::parse($date);

    $visits = BuildingVisit::with([
        'building',
        'user',
        'workOrder',
        'deliveryNote',
    ])
    ->whereHas('building.users', fn($q) => $q->where('users.id', auth()->id()))
    ->where(function($query) use ($date){

        // visitas normales
        $query->whereDate('visited_at', $date)

        // work orders
        ->orWhere(function($q) use ($date){

            $q->where('source', 'work_order')
              ->whereDate('finished_at', $date);

        });

    })
    ->orderByRaw('COALESCE(started_at, visited_at) ASC')
    ->orderBy('visited_at')
    ->get();


    return view(
        'templates.day',
        compact('visits', 'date')
    );
}

public function userTemplate($company, User $user)
{
    abort_unless(auth()->user()->isAdmin(), 403);

    Carbon::setLocale('es');

    $month = request('month', now()->month);
    $year  = request('year', now()->year);

    $visits = BuildingVisit::with([
        'building',
        'workOrder',
        'user',
    ])
    ->whereHas('building.users', fn($q) => $q->where('users.id', $user->id))
    ->whereNotNull('visited_at')
    ->whereMonth('visited_at', $month)
    ->whereYear('visited_at', $year)
    ->orderBy('visited_at')
    ->get();

    $weeks = [];

    $current = Carbon::create($year, $month, 1)
        ->startOfWeek(Carbon::MONDAY);

    $end = Carbon::create($year, $month, 1)
        ->endOfMonth()
        ->endOfWeek(Carbon::SUNDAY);

    while ($current->lte($end)) {

        $start = $current->copy()->startOfDay();
        $finish = $current->copy()->addDays(6)->endOfDay();

        $weeks[] = [
            'start' => $start,
            'end' => $finish,
            'visits' => $visits->filter(fn($v) =>
                $v->visited_at->between($start, $finish)
            ),
        ];

        $current->addWeek();
    }

    return view('admin.user-template', compact(
        'user',
        'weeks',
        'month',
        'year'
    ));
}

public function userTemplateDay($company, User $user, $date)
{
    $date = Carbon::parse($date);

    $visits = BuildingVisit::with([
        'building.client',
        'user',
        'workOrder',
        'deliveryNote',
    ])
    ->whereHas('building.users', fn($q) => $q->where('users.id', $user->id))
    ->whereDate('visited_at', $date)
    ->orderByRaw('COALESCE(started_at, visited_at)')
    ->get();

    return view('templates.day', [
        'visits' => $visits,
        'date' => $date,
        'user' => $user,
    ]);
}
}
