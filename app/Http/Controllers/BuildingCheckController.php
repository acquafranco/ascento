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

        $this->authorizeBuilding(
            $building
        );

        $now = Carbon::now();

        /*
        |--------------------------------------------------------------------------
        | SOLO EDIFICIOS FIJOS
        |--------------------------------------------------------------------------
        */

        $visit =
            BuildingVisit::where(
                'building_id',
                $building->id
            )
            ->where(
                'user_id',
                auth()->id()
            )
            ->where(
                'month',
                now()->month
            )
            ->where(
                'year',
                now()->year
            )
            ->where(
                'visit_type',
                'fixed'
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
        | CREAR MANTENIMIENTO FIJO
        |--------------------------------------------------------------------------
        */

        BuildingVisit::create([

            'building_id' =>
                $building->id,

            'user_id' =>
                auth()->id(),

            'visit_type' =>
                'fixed',

            'status' =>
                'done',

            'month' =>
                $now->month,

            'year' =>
                $now->year,

            'delivery_note' =>
                $request->delivery_note,

            'visited_at' =>
                $now,
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
