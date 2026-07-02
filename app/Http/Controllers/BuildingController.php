<?php

namespace App\Http\Controllers;

use App\Models\Building;

class BuildingController extends Controller
{
    public function index()
    {
        $user = auth()->user();

        /*
        |--------------------------------------------------------------------------
        | SOLO VER ASIGNADOS
        |--------------------------------------------------------------------------
        */

        $buildings = $user
            ->buildings()
            ->with('client')
            ->paginate(20);

        return view(
            'buildings.index',
            compact('buildings')
        );
    }

    public function show(Building $building)
    {
        /*
        |--------------------------------------------------------------------------
        | SEGURIDAD
        |--------------------------------------------------------------------------
        */

        abort_unless(

            auth()->user()
                ->buildings()
                ->where('buildings.id', $building->id)
                ->exists(),

            403
        );

        return view(
            'buildings.show',
            compact('building')
        );
    }
}
