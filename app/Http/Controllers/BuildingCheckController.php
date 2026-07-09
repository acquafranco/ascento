<?php

namespace App\Http\Controllers;

use App\Models\Building;
use App\Models\BuildingVisit;
use Illuminate\Http\Request;

class BuildingCheckController extends Controller
{

    public function done(
        Request $request,
        Building $building
    ) {

        $this->authorizeBuilding($building);


        $month = $request->month ?? now()->month;
        $year  = $request->year ?? now()->year;


        /*
        |--------------------------------------------------------------------------
        | BUSCAR MANTENIMIENTO DEL PERIODO
        |--------------------------------------------------------------------------
        */
        $assignmentType = $building
            ->users()
            ->where('users.id', auth()->id())
            ->first()
            ?->pivot
            ->type;

        $visit = BuildingVisit::where('building_id', $building->id)
            ->where('user_id', auth()->id())
            ->where('visit_type', 'fixed')
            ->where('assignment_type', $assignmentType)
            ->where('month', $month)
            ->where('year', $year)
            ->first();


        /*
        |--------------------------------------------------------------------------
        | SI EXISTE, DESMARCAR
        |--------------------------------------------------------------------------
        */

        if($visit){

            $visit->delete();

            return back()->with(
                'success',
                'Mantenimiento desmarcado correctamente.'
            );

        }


        /*
        |--------------------------------------------------------------------------
        | SI NO EXISTE NO CREA NADA
        |--------------------------------------------------------------------------
        */

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
