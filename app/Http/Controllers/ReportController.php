<?php

namespace App\Http\Controllers;

use App\Models\Report;
use App\Models\Building;
use App\Models\Company;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Notifications\NewReportNotification;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\ImageManager;
use Intervention\Image\Drivers\Imagick\Driver as ImagickDriver;
use Intervention\Image\Encoders\WebpEncoder;
use Intervention\Image\Encoders\JpegEncoder;

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

        // Cupo mensual de reportes del plan: se avisa ANTES de completar el formulario.
        $guard = \App\Support\Plans\PlanGuard::for($company);
        $limit = \App\Enums\PlanLimit::ReportsPerMonth;

        if (! $guard->canAdd($limit)) {
            \App\Jobs\NotifyCompanyAdmins::reportLimitReached($company);

            return view('reports.limit', [
                'company' => $company,
                'message' => $guard->limitReachedMessage($limit),
            ]);
        }

        $planUsage = $guard->limit($limit) !== null ? [
            'label' => $guard->usageLabel($limit),
            'warning' => $guard->warning($limit),
        ] : null;

        $buildings = Building::where(
            'company_id',
            $company->id
        )
        ->where('is_active',true)
        ->orderBy('name')
        // Solo lo que usa el buscador del formulario (se serializa a JSON
        // en la página).
        ->get(['id', 'name', 'address', 'elevator_count', 'freight_elevator_count']);


        return view(
            'reports.create',
            compact(
                'buildings',
                'company',
                'planUsage'
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

        // Antes de procesar la foto: ¿queda cupo de reportes este mes?
        if (! \App\Support\Plans\PlanGuard::for($company)->canAdd(\App\Enums\PlanLimit::ReportsPerMonth)) {
            \App\Jobs\NotifyCompanyAdmins::reportLimitReached($company);

            return redirect()
                ->route('reports.create', ['company' => $company->slug]);
        }

        $data = $request->validate([

            'building_id'=>'required|integer',
            'elevator_number'=>'required|string|max:255',
            'description'=>'required|string|min:5|max:5000',
            'priority'=>'required|in:baja,media,alta,critica',
            // Solo fotos: nada de SVG/PDF/etc. (se decodifican con Imagick).
            'photo' => [
                'required',
                'file',
                'max:10240',
                'mimes:jpg,jpeg,png,webp,heic,heif',
            ],

        ], [
            'building_id.required'=>'Tenés que seleccionar un edificio.',

            'elevator_number.required'=>'Tenés que seleccionar un equipo.',
            'description.required'=>'La descripción es obligatoria.',
            'description.min'=>'La descripción debe tener al menos 5 caracteres.',
            'priority.required'=>'Seleccioná una prioridad.',
            'photo.required'=>'Tenés que adjuntar una imagen.',
            'photo.mimes'=>'Formato de imagen no permitido. Usá JPG, PNG, WEBP o HEIC.',
            'photo.max'=>'La imagen no puede superar los 10 MB.',
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

            try {
                $folder = 'reports/'.$company->id;

                $filename = Str::random(40).'.jpg';

                // Disco privado (storage/app/private): la foto solo se sirve
                // por ReportPhotoController, que valida empresa y permisos.
                $fullPath = Storage::disk('local')->path($folder.'/'.$filename);

                if (!file_exists(dirname($fullPath))) {
                    mkdir(dirname($fullPath), 0755, true);
                }

                $manager = ImageManager::usingDriver(ImagickDriver::class);

                $image = $manager->decode(fopen($request->file('photo')->getRealPath(), 'rb'));


                $image->encode(new \Intervention\Image\Encoders\JpegEncoder(quality: 90))
                ->save($fullPath);


                $data['photo'] = $folder.'/'.$filename;

            } catch (\Throwable $e) {
                logger()->error('Error procesando imagen de reporte', [
                    'message' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                ]);

                return back()
                    ->withErrors([
                        'photo' => 'No se pudo procesar la imagen. Probá con otra foto o formato.'
                    ])
                    ->withInput();
            }
        }



        $report = Report::create([

            ...$data,

            'company_id'=>$company->id,

            'user_id'=>Auth::id(),

        ]);



        $report->load('building');


        // Aviso a los admins de la empresa (campanita + push), después de
        // responder: el técnico no espera.
        \App\Jobs\NotifyCompanyAdmins::reportCreated($report);



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
