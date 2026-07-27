<?php

namespace App\Http\Controllers;

use App\Models\Building;
use Illuminate\Http\Request;
use App\Models\BuildingVisit;
use App\Models\DeliveryNote;

class BuildingController extends Controller
{
    public function index(Request $request)
{
    $user = auth()->user();

    $month = $request->get('month', now()->month);
    $year = $request->get('year', now()->year);
    $date = $request->get('date');


    $buildings = $user
        ->buildings()
        ->with([
            'client',
            'visits' => function($query) use ($month,$year,$date){

                $query
                    ->where('visit_type','fixed')
                    ->where('month',$month)
                    ->where('year',$year);

                if($date){
                    $query->whereDate('visited_at',$date);
                }

            }
        ])
        ->paginate(20);


    $visitsToday = BuildingVisit::where('user_id',$user->id)
        ->whereDate('visited_at',today())
        ->where('status','completed')
        ->count();


    return view('buildings.index',compact(
        'buildings',
        'month',
        'year',
        'date',
        'visitsToday'
    ));
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

public function all(Request $request)
{
    $search = $request->get('search');


    $buildings = Building::with('client')
        ->where('company_id', auth()->user()->company_id)

        ->when($search, function($query) use ($search){

            $query->where(function($q) use ($search){

                $q->where('name','like',"%{$search}%")
                ->orWhere('address','like',"%{$search}%")
                ->orWhere('client_name','like',"%{$search}%")
                ->orWhere('phone','like',"%{$search}%");

            });

        })

        ->select([
            'id',
            'name',
            'address',
            'client_id',
            'client_name',
            'contact_person',
            'phone',
            'elevator_count',
            'freight_elevator_count',
            'notes'
        ])

        ->orderBy('name')

        ->paginate(20)
        ->withQueryString();


    return view(
        'buildings.all',
        compact('buildings','search')
    );
}
}
