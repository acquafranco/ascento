<?php

namespace App\Http\Controllers;

use App\Models\Building;
use App\Models\BuildingVisit;
use Illuminate\Http\Request;
use Carbon\Carbon;

class BuildingCheckController extends Controller
{
    public function done(
        Request $request,
        Building $building
    ) {

        $this->authorizeBuilding($building);

        /*
        |--------------------------------------------------------------------------
        | PERIODO SELECCIONADO
        |--------------------------------------------------------------------------
        */

        $month = $request->month ?? now()->month;
        $year  = $request->year ?? now()->year;

        /*
        |--------------------------------------------------------------------------
        | BUSCAR VISITA DEL PERIODO
        |--------------------------------------------------------------------------
        */

        $visit = BuildingVisit::where(
                'building_id',
                $building->id
            )
            ->where(
                'user_id',
                auth()->id()
            )
            ->where(
                'visit_type',
                'fixed'
            )
            ->where(
                'month',
                $month
            )
            ->where(
                'year',
                $year
            )
            ->first();

        /*
        |--------------------------------------------------------------------------
        | DESMARCAR
        |--------------------------------------------------------------------------
        */

        if ($visit) {

            $visit->delete();

            return back();

        }

        /*
        |--------------------------------------------------------------------------
        | CREAR MANTENIMIENTO
        |--------------------------------------------------------------------------
        */

        BuildingVisit::create([

            'building_id' => $building->id,

            'user_id' => auth()->id(),

            'visit_type' => 'fixed',

            'status' => 'done',

            'month' => $month,

            'year' => $year,

            'delivery_note' => $request->delivery_note,

            'visited_at' => now(),

        ]);

        return back();
    }

    private function authorizeBuilding(
        Building $building
    ): void {

        abort_unless(

            auth()->user()
                ->buildings()
                ->where(
                    'buildings.id',
                    $building->id
                )
                ->exists(),

            403

        );

    }
}


