<?php

namespace App\Http\Controllers;

use App\Models\Report;
use App\Models\Building;
use App\Models\Company;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Filament\Notifications\Notification;
use App\Models\User;


class ReportController extends Controller
{


    public function index(Company $company)
{
    abort_unless(
        auth()->user()->company_id === $company->id,
        403
    );
    $reports = Report::with([
            'building',
            'user'
        ])
        ->where('company_id', $company->id)
        ->where('company_id', auth()->user()->company_id)
        ->where('user_id', auth()->id())
        ->latest()
        ->paginate(15);

    return view('reports.index', compact('reports', 'company'));
}


    public function create(Company $company)
    {
        abort_unless(
            auth()->user()->company_id === $company->id,
            403
        );

        $buildings = Building::where(
            'company_id',
            $company->id
        )
        ->where('is_active',true)
        ->get();


        return view(
            'reports.create',
            compact(
                'buildings',
                'company'
            )
        );

    }

    public function show(Company $company, Report $report)
    {
        abort_unless($report->company_id === $company->id, 403);
        abort_unless($report->company_id === auth()->user()->company_id, 403);
        abort_unless($report->user_id === Auth::id(), 403);

        $report->load([
            'building',
            'user',
        ]);

        return view('reports.show', compact('report', 'company'));
    }


    public function store(
        Request $request,
        Company $company
    )
    {
        abort_unless(
            auth()->user()->company_id === $company->id,
            403
        );

        $data = $request->validate([

            'building_id'=>'required|exists:buildings,id',
            'elevator_number'=>'required|string|max:255',
            'description'=>'required|string|min:5',
            'priority'=>'required|in:baja,media,alta,critica',
            'photo' => [
                'required',
                'file',
                'mimetypes:image/jpeg,image/png,image/webp,image/heic,image/heif',
                'max:5120'
            ],

        ], [
            'building_id.required'=>'Tenés que seleccionar un edificio.',
            'building_id.exists'=>'El edificio seleccionado no existe.',
            'elevator_number.required'=>'Tenés que seleccionar un equipo.',
            'description.required'=>'La descripción es obligatoria.',
            'description.min'=>'La descripción debe tener al menos 5 caracteres.',
            'priority.required'=>'Seleccioná una prioridad.',
            'photo.required'=>'Tenés que adjuntar una imagen.',
            'photo.mimetypes'=>'La imagen debe ser JPG, PNG o WEBP.',
            'photo.max'=>'La imagen no puede superar los 2 MB.',
        ]);

        if (!Building::where('id', $data['building_id'])
            ->where('company_id', $company->id)
            ->exists()) {

            return back()
                ->withErrors([
                    'building_id' => 'El edificio seleccionado no pertenece a esta empresa.'
                ])
                ->withInput();
        }



        if($request->hasFile('photo')){

            $data['photo'] =
                $request->file('photo')
                ->store('reports/'.$company->id, 'public');

        }



        $report = Report::create([

            ...$data,

            'company_id'=>$company->id,

            'user_id'=>Auth::id(),

        ]);


        $report->load('building');


        $admins = User::where('company_id', $company->id)
            ->where('role', 'admin')
            ->get();


        foreach ($admins as $admin) {

            Notification::make()
                ->title('Nuevo reporte recibido')
                ->body(
                    'Se creó un reporte en '.$report->building->name
                )
                ->sendToDatabase($admin);

        }



        return redirect()
            ->route(
                'reports.index',
                [
                    'company'=>$company->slug
                ]
            )
            ->with(
                'success',
                'Reporte creado correctamente'
            );

    }


}
