<?php

namespace App\Http\Controllers;

use App\Models\Building;
use Illuminate\Http\Request;

class BuildingController extends Controller
{
    public function index(Request $request)
    {
        $user = auth()->user();

        $month = $request->get('month', now()->month);
        $year = $request->get('year', now()->year);

        $buildings = $user
            ->buildings()
            ->with('client')
            ->get()
            ->unique('id')
            ->values();

        return view(
            'buildings.index',
            compact(
                'buildings',
                'month',
                'year'
            )
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
