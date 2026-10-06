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

        $currentCompanyId = auth()->user()->company_id;

        [$month, $year] = $this->period();

        /*
        |--------------------------------------------------------------------------
        | VISITAS DEL MES
        |--------------------------------------------------------------------------
        */

        $visits = BuildingVisit::with([
            'building.client',
            'building.users',
            'workOrder.users',
            'user',
            'deliveryNote',
            'participants',
        ])
        ->where('company_id', $currentCompanyId)
        ->where(function ($query) {
            $query
                ->where(function ($q) {
                    $q->where('source', 'work_order')
                      ->whereHas('participants', fn($users) => $users->where('users.id', auth()->id()));
                })
                ->orWhere('source', 'building');
        })
        ->whereNotNull('visited_at')
        ->whereMonth('visited_at', $month)
        ->whereYear('visited_at', $year)
        ->orderBy('visited_at')
        ->get();

        // Filtro personalizado
        $visits = $visits->filter(function ($visit) use ($currentCompanyId) {
            if ($visit->company_id !== $currentCompanyId) {
                return false;
            }

            $currentUser = auth()->user();

            if ($visit->source === 'work_order') {
                return $visit->participants->contains('id', $currentUser->id);
            }

            // Inspecciones: pertenecen al técnico que realmente la realizó.
            if ($visit->assignment_type === 'inspection') {
                return $visit->user_id === $currentUser->id;
            }

            // Mantenimientos: mostrar a quienes realmente participaron.
            if ($visit->assignment_type === 'maintenance') {
                return $visit->participants->contains('id', $currentUser->id);
            }

            return false;
        })->values();

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
    $currentCompanyId = auth()->user()->company_id;

    $date = $this->parseDate($date);

    $visits = BuildingVisit::with([
        'building.client',
        'building.users',
        'user',
        'workOrder.users',
        'deliveryNote',
        'participants',
    ])
    ->where('company_id', $currentCompanyId)
    ->where(function ($query) {
        $query
            ->where(function ($q) {
                $q->where('source', 'work_order')
                  ->whereHas('participants', fn($users) => $users->where('users.id', auth()->id()));
            })
            ->orWhere('source', 'building');
    })
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

    // Filtro personalizado
    $visits = $visits->filter(function ($visit) use ($currentCompanyId) {
        if ($visit->company_id !== $currentCompanyId) {
            return false;
        }

        $currentUser = auth()->user();

        if ($visit->source === 'work_order') {
            return $visit->participants->contains('id', $currentUser->id);
        }

        // Inspecciones: pertenecen al técnico que realmente la realizó.
        if ($visit->assignment_type === 'inspection') {
            return $visit->user_id === $currentUser->id;
        }

        // Mantenimientos: mostrar a quienes realmente participaron.
        if ($visit->assignment_type === 'maintenance') {
            return $visit->participants->contains('id', $currentUser->id);
        }

        return false;
    })->values();

    return view(
        'templates.day',
        compact('visits', 'date')
    );
}

public function userTemplate($company, User $user)
{
    $currentCompanyId = auth()->user()->company_id;

    abort_unless($user->company_id === $currentCompanyId, 404);

    abort_unless(auth()->user()->isAdmin(), 403);

    Carbon::setLocale('es');

    [$month, $year] = $this->period();

    $visits = BuildingVisit::with([
        'building.client',
        'building.users',
        'workOrder.users',
        'user',
        'deliveryNote',
        'participants',
    ])
    ->where('company_id', $currentCompanyId)
    ->where(function ($query) use ($user) {
        $query
            ->where(function ($q) use ($user) {
                $q->where('source', 'work_order')
                  ->whereHas('participants', fn($users) => $users->where('users.id', $user->id));
            })
            ->orWhere('source', 'building');
    })
    ->whereNotNull('visited_at')
    ->whereMonth('visited_at', $month)
    ->whereYear('visited_at', $year)
    ->orderBy('visited_at')
    ->get();

    // Filtro personalizado
    $visits = $visits->filter(function ($visit) use ($user, $currentCompanyId) {
        if ($visit->company_id !== $currentCompanyId) {
            return false;
        }

        $currentUser = isset($user) ? $user : auth()->user();

        if ($visit->source === 'work_order') {
            return $visit->participants->contains('id', $currentUser->id);
        }

        // Inspecciones: pertenecen al técnico que realmente la realizó.
        if ($visit->assignment_type === 'inspection') {
            return $visit->user_id === $currentUser->id;
        }

        // Mantenimientos: mostrar a quienes realmente participaron.
        if ($visit->assignment_type === 'maintenance') {
            return $visit->participants->contains('id', $currentUser->id);
        }

        return false;
    })->values();

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
    $currentCompanyId = auth()->user()->company_id;

    abort_unless($user->company_id === $currentCompanyId, 404);

    $date = $this->parseDate($date);

    $visits = BuildingVisit::with([
        'building.client',
        'building.users',
        'user',
        'workOrder.users',
        'deliveryNote',
        'participants',
    ])
    ->where('company_id', $currentCompanyId)
    ->where(function ($query) use ($user) {
        $query
            ->where(function ($q) use ($user) {
                $q->where('source', 'work_order')
                  ->whereHas('participants', fn($users) => $users->where('users.id', $user->id));
            })
            ->orWhere('source', 'building');
    })
    ->whereDate('visited_at', $date)
    ->orderByRaw('COALESCE(started_at, visited_at)')
    ->get();

    // Filtro personalizado
    $visits = $visits->filter(function ($visit) use ($user, $currentCompanyId) {
        if ($visit->company_id !== $currentCompanyId) {
            return false;
        }

        $currentUser = isset($user) ? $user : auth()->user();

        if ($visit->source === 'work_order') {
            return $visit->participants->contains('id', $currentUser->id);
        }

        // Inspecciones: pertenecen al técnico que realmente la realizó.
        if ($visit->assignment_type === 'inspection') {
            return $visit->user_id === $currentUser->id;
        }

        // Mantenimientos: mostrar a quienes realmente participaron.
        if ($visit->assignment_type === 'maintenance') {
            return $visit->participants->contains('id', $currentUser->id);
        }

        return false;
    })->values();

    return view('templates.day', [
        'visits' => $visits,
        'date' => $date,
        'user' => $user,
    ]);
}

    /**
     * Mes/año pedidos por query string, validados (antes un valor no
     * numérico terminaba en un 500 al armar las semanas).
     *
     * @return array{0: int, 1: int}
     */
    private function period(): array
    {
        $data = validator(request()->only('month', 'year'), [
            'month' => 'nullable|integer|between:1,12',
            'year' => 'nullable|integer|between:2000,2100',
        ])->validate();

        return [
            (int) ($data['month'] ?? now()->month),
            (int) ($data['year'] ?? now()->year),
        ];
    }

    private function parseDate(string $date): Carbon
    {
        try {
            $parsed = Carbon::createFromFormat('!Y-m-d', $date);
        } catch (\Throwable) {
            abort(404);
        }

        abort_unless($parsed && $parsed->format('Y-m-d') === $date, 404);

        return $parsed;
    }
}
