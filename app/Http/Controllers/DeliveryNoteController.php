<?php

namespace App\Http\Controllers;

use App\Models\Building;
use App\Models\WorkOrder;
use App\Models\BuildingVisit;
use App\Models\DeliveryNote;
use App\Models\StockItem;
use App\Models\WorkOrderMaterial;
use Illuminate\Http\Request;
use Barryvdh\DomPDF\Facade\Pdf;
use App\Models\Company;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use App\Support\DeliveryNotes\DeliveryNoteDocument;
use Symfony\Component\HttpFoundation\Response;

class DeliveryNoteController extends Controller
{
   public function index(Request $request, Company $company)

{

    $user = auth()->user();

    $query = DeliveryNote::with([
    'building',
    'user',
    'workOrder',
    'buildingVisit',
    ])->where('company_id', $company->id);

    if (!$user->isAdmin()) {
    $query->where(function ($q) use ($user) {

        // Remitos propios
        $q->where('delivery_notes.user_id', $user->id)

        // O remitos de una visita donde el usuario participó
        ->orWhereHas('buildingVisit.participants', function ($participants) use ($user) {
            $participants->where('users.id', $user->id);
        });
    });
}

    if ($request->filled('day')) {

        $query->whereDay(

            'created_at',

            $request->day

        );

    }

    if ($request->filled('month')) {

        $query->whereMonth(

            'created_at',

            $request->month

        );

    }

    if ($request->filled('year')) {

        $query->whereYear(

            'created_at',

            $request->year

        );

    }

    // Paginado: con el historial de un año, dibujar todos trababa el celular.
    $deliveryNotes = $query
        ->latest()
        ->paginate(20)
        ->withQueryString();

    return view(

        'delivery-notes.index',

        compact('deliveryNotes')

    );

}
    public function show(Company $company, DeliveryNote $deliveryNote)
    {
        $user = auth()->user();

        // La empresa de la URL debe coincidir con el remito
        if ($deliveryNote->company_id != $company->id) {
            abort(404);
        }

        // Usuarios normales solo ven sus remitos
        if (!$user->isAdmin() && !$user->isSuperAdmin()) {

            $isOwner = $deliveryNote->user_id === $user->id;

            $isParticipant = $deliveryNote->buildingVisit()
                ->whereHas('participants', function ($query) use ($user) {
                    $query->where('users.id', $user->id);
                })
                ->exists();

            if (!$isOwner && !$isParticipant) {
                abort(404);
            }
        }

        $deliveryNote->load(static::RELATIONS);

        return view('delivery-notes.show', [
            'deliveryNote' => $deliveryNote,
            'doc' => DeliveryNoteDocument::for($deliveryNote),
            'audience' => 'internal',
            'photos' => $this->visitPhotosFor($deliveryNote, $user),
        ]);
    }

    /**
     * Fotos de la visita del remito que este usuario puede ver (todas son de
     * la misma visita: alcanza con verificar una; VisitPhoto::canBeViewedBy).
     */
    private function visitPhotosFor(DeliveryNote $deliveryNote, $user)
    {
        if (! $deliveryNote->building_visit_id) {
            return collect();
        }

        $photos = \App\Models\VisitPhoto::where('building_visit_id', $deliveryNote->building_visit_id)
            ->whereNotNull('attached_at')
            ->with(['elevator:id,label', 'uploader:id,name'])
            ->orderBy('photo_section_id')->orderBy('id')->get();

        return $photos->isNotEmpty() && $photos->first()->canBeViewedBy($user) ? $photos : collect();
    }

    /** Lo que muestra el remito (web y PDF). */
    public const RELATIONS = [
        'company',
        'building.client',
        'user',
        'workOrder.participants',
        'workOrder.materials.stockItem',
        'buildingVisit.participants',
    ];

    /** PDF real del remito (antes la ruta devolvía la página HTML para imprimir). */
    public static function pdfResponse(DeliveryNote $deliveryNote): Response
    {
        $content = Pdf::loadView('delivery-notes.pdf', ['doc' => DeliveryNoteDocument::for($deliveryNote, pdf: true)])
            ->setPaper('a4')
            // Solo los caracteres usados de la fuente: el archivo pasa de ~900 KB a unos pocos.
            ->setOption('isFontSubsettingEnabled', true)
            ->output();

        return response($content, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="remito-'.str_pad((string) $deliveryNote->number, 6, '0', STR_PAD_LEFT).'.pdf"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function createFromBuilding(Request $request, $company, Building $building)
    {
        if ($building->company_id !== auth()->user()->company_id) {
            abort(404);
        }

        $this->ensureAssignedTo($building, (string) $request->assignment_type);

        $month = (int) ($request->month ?? now()->month);
        $month = $month >= 1 && $month <= 12 ? $month : now()->month;
        $year = (int) ($request->year ?? now()->year);
        $year = $year >= 2000 && $year <= 2100 ? $year : now()->year;

        // Qué visita del período se va a registrar (solo informativo: el
        // servidor lo vuelve a calcular al guardar).
        $period = \App\Services\Visits\VisitSchedule::load([$building->id], $month, $year, (int) $building->company_id)
            ->period($building->id, (string) $request->assignment_type, $month, $year);
        $slot = $this->periodicSlot($building, (string) $request->assignment_type, $month, $year);
        $batch = \Illuminate\Support\Str::isUuid((string) old('photo_batch')) ? (string) old('photo_batch') : (string) \Illuminate\Support\Str::uuid();

        return view(
            'delivery-notes.create',
            [
                'building' => $building,
                'workOrder' => null,
                'month' => $month,
                'year' => $year,
                'assignmentType' => $request->assignment_type,
                'visitInfo' => [
                    'slot' => is_int($slot) ? $slot : null,
                    'blocked' => is_string($slot) ? $slot : null,
                    'expected' => $period->expected,
                    'period' => $period->isMultiMonth() ? $period->label() : null,
                    'frequency' => $period->frequencyLabel(),
                ],
                'photoSections' => \App\Models\PhotoSection::forCompany((int) $building->company_id),
                'elevators' => $building->elevators()->where('is_active', true)->get(['id', 'building_id', 'kind', 'label']),
                // Si el envío volvió con errores, se conserva el lote y sus fotos ya subidas.
                'photoBatch' => $batch,
                'stagedPhotos' => \App\Models\VisitPhoto::withoutGlobalScopes()
                    ->where('batch', $batch)->where('uploaded_by', auth()->id())
                    ->where('building_id', $building->id)->whereNull('building_visit_id')
                    ->orderBy('id')->get(),
            ]
        );
    }

    public function createFromWorkOrder(Request $request, $company, WorkOrder $workOrder)
    {
        $user = auth()->user();

        if ($workOrder->company_id !== $user->company_id) {
            abort(404);
        }

        // La única autorización necesaria para abrir el remito es estar asignado a la orden.
        // Los técnicos que no están asignados reciben 403.
        if (!$workOrder->users()->whereKey($user->id)->exists()) {
            abort(403, 'No estás autorizado para finalizar esta orden.');
        }

        // No validar aquí ningún parámetro `technician`: el usuario autenticado ya fue
        // autorizado por su asignación real en la relación de la WorkOrder.

        // Una orden de trabajo solo puede tener un remito.
        if ($workOrder->deliveryNote()->exists()) {
            abort(409, 'Esta orden de trabajo ya tiene un remito.');
        }

        // El remito solo puede abrirse mientras la orden está en progreso.
        if ($workOrder->status !== 'in_progress') {
            abort(409, 'Esta orden de trabajo ya no está en progreso.');
        }

        $workOrder->load('materials.stockItem');

        return view(
            'delivery-notes.create',
            [
                'building' => $workOrder->building,
                'workOrder' => $workOrder,
                // Solo lectura para el técnico: nombre, unidad y stock (sin costos).
                'stockItems' => StockItem::where('company_id', $user->company_id)
                    ->active()
                    ->orderBy('name')
                    ->get(['id', 'name', 'code', 'unit', 'current_stock']),
                'month' => $request->month ?? now()->month,
                'year' => $request->year ?? now()->year,
                'assignmentType' => $workOrder->type === 'inspection'
                    ? 'inspection'
                    : 'maintenance',
            ]
        );
    }

    public function store(Request $request)
{
    $companyId = auth()->user()->company_id;

    // Firma dibujada en canvas: solo data URLs de imagen, con tope de tamaño.
    $signatureRule = 'regex:/^data:image\/(png|jpeg);base64,[A-Za-z0-9+\/=]+$/';

    $request->validate(
        [
            'building_id' => 'required|integer',
            'work_order_id' => 'nullable|integer',
            'description' => 'required|string|max:5000',
            'month' => 'nullable|integer|between:1,12',
            'year' => 'nullable|integer|between:2000,2100',
            'elevator_quantity' => 'required|integer|min:0|max:500',
            'freight_elevator_quantity' => 'required|integer|min:0|max:500',
            'assignment_type' => [
                'required',
                $request->filled('work_order_id')
                    ? Rule::in(['maintenance', 'inspection', 'work_order'])
                    : Rule::in(['maintenance', 'inspection']),
            ],
            'signature_name' => 'required|string|max:255',
            'signature' => ['required', 'string', 'min:100', 'max:1000000', $signatureRule],
            'client_signature' => ['nullable', 'string', 'max:1000000', $signatureRule],
            'client_signature_name' => 'nullable|string|max:255',
            // Materiales que el técnico declara al cerrar una orden. Solo
            // material activo de SU empresa y cantidades positivas; el costo,
            // la empresa y el stock nunca salen del request.
            'materials' => 'nullable|array|max:20',
            'materials.*.stock_item_id' => [
                'required',
                'integer',
                Rule::exists('stock_items', 'id')
                    ->where('company_id', $companyId)
                    ->where('is_active', true)
                    ->whereNull('deleted_at'),
            ],
            'materials.*.quantity' => 'required|numeric|gt:0|max:99999|decimal:0,2',
            // Lote de fotos de antes y después (ya subidas de a una).
            'photo_batch' => 'nullable|uuid',
            'photo_notes' => 'nullable|array|max:50',
            'photo_notes.*' => 'nullable|string|max:500',
            'photo_elevators' => 'nullable|array|max:50',
            'photo_elevators.*' => 'nullable|integer',
            'participants' => 'nullable|array|max:20',
            // Los participantes tienen que ser de la MISMA empresa.
            'participants.*' => [
                'integer',
                Rule::exists('users', 'id')->where('company_id', $companyId)->whereIn('role', ['admin', 'technician']),
            ],
        ],
        [
            'description.required' => 'Debe escribir el trabajo realizado.',
            'signature.required' => 'Debe realizar la firma del técnico.',
            'signature.regex' => 'La firma del técnico no es válida. Volvé a firmar.',
            'client_signature.regex' => 'La firma del cliente no es válida. Volvé a firmar.',
            'signature_name.required' => 'Debe escribir el nombre del técnico.',
            'building_id.required' => 'No se encontró el edificio.',
            'assignment_type.required' => 'No se pudo identificar el tipo de trabajo.',
            'assignment_type.in' => 'El tipo de trabajo no es válido.',
            'participants.*.exists' => 'Uno de los participantes no pertenece a tu empresa.',
            'materials.max' => 'Podés declarar hasta 20 materiales.',
            'photo_notes.*.max' => 'Cada observación de fotos puede tener hasta 500 caracteres.',
            'materials.*.stock_item_id.required' => 'Elegí el material.',
            'materials.*.stock_item_id.exists' => 'Uno de los materiales no existe o no está disponible.',
            'materials.*.quantity.required' => 'Indicá la cantidad de cada material.',
            'materials.*.quantity.gt' => 'La cantidad tiene que ser mayor que cero.',
            'materials.*.quantity.numeric' => 'La cantidad tiene que ser un número.',
            'materials.*.quantity.decimal' => 'La cantidad admite hasta 2 decimales.',
            'materials.*.quantity.max' => 'La cantidad es demasiado grande.',
        ]
    );

    // month/year son NOT NULL en la base: si no vienen, el período actual.
    $request->merge([
        'month' => $request->filled('month') ? (int) $request->input('month') : now()->month,
        'year' => $request->filled('year') ? (int) $request->input('year') : now()->year,
    ]);

    return DB::transaction(function () use ($request, $companyId) {

        // Serializa la creación de remitos por empresa: el número se
        // calcula como max(number) + 1 y dos técnicos simultáneos
        // obtendrían el mismo valor (violando el unique company+number).
        Company::whereKey($companyId)->lockForUpdate()->first();

        $workOrder = null;

        if ($request->filled('work_order_id')) {
            $workOrder = WorkOrder::where('company_id', auth()->user()->company_id)
                ->where('id', $request->work_order_id)
                ->lockForUpdate()
                ->firstOrFail();

            // La única autorización necesaria para crear el remito es estar asignado a la orden.
            // Un usuario que no esté asignado recibe 403.
            if (!$workOrder->users()->whereKey(auth()->id())->exists()) {
                abort(403, 'No estás autorizado para finalizar esta orden.');
            }

            // IMPORTANTE: no validar `technician` ni `hasValidSignature()` en este POST.
            // `signature` es el campo de firma dibujada del formulario y NO es la firma
            // criptográfica de una URL firmada. Comprobar hasValidSignature() aquí provoca
            // un 403 al enviar el formulario aunque el técnico sí esté asignado.

            // Una orden no puede tener más de un remito.
            if ($workOrder->deliveryNote()->exists()) {
                abort(409, 'Esta orden de trabajo ya tiene un remito.');
            }

            // El remito solo puede crearse mientras la orden está en progreso.
            if ($workOrder->status !== 'in_progress') {
                abort(409, 'Esta orden de trabajo ya no está en progreso.');
            }
        }

        // El remito de una orden es SIEMPRE del edificio de la orden (no del
        // building_id que mande el formulario).
        $building = Building::where('company_id', auth()->user()->company_id)
            ->where('id', $workOrder?->building_id ?? $request->building_id)
            ->firstOrFail();

        // Mantenimiento / inspección mensual: solo de un edificio asignado al
        // técnico para ese tipo de trabajo (lo mismo que le ofrece la pantalla).
        if (! $workOrder) {
            $this->ensureAssignedTo($building, (string) $request->assignment_type);
        }

        $assignmentType = $request->filled('work_order_id')
        ? 'work_order'
        : $request->assignment_type;


    /*
    |--------------------------------------------------------------------------
    | VISITA PERIÓDICA (mantenimiento / inspección)
    |--------------------------------------------------------------------------
    | Qué número de visita le toca sale de VisitSchedule (frecuencia del
    | edificio): mensual = 1 por mes, quincenal = 2 por mes, cada N meses = 1
    | por período. Cada visita tiene su identidad (period_key, única en la
    | base): dos remitos simultáneos nunca crean la misma visita.
    */

    $visit = null;

    if (! $request->filled('work_order_id')) {
        $slot = $this->periodicSlot($building, (string) $assignmentType, (int) $request->month, (int) $request->year);

        if (is_string($slot)) {
            return back()->withErrors(['general' => $slot]);
        }

        try {
            $visit = BuildingVisit::create([
                'company_id' => auth()->user()->company_id,
                'building_id' => $building->id,
                'user_id' => auth()->id(),
                'visit_type' => 'fixed',
                'assignment_type' => $assignmentType,
                'month' => $request->month,
                'year' => $request->year,
                'slot' => $slot,
                'status' => 'done',
                'visited_at' => now(),
                'source' => 'building',
            ]);
        } catch (\Illuminate\Database\UniqueConstraintViolationException) {
            // Otro técnico registró esa misma visita en el mismo instante.
            return back()->withErrors(['general' => 'Esa visita se acaba de registrar desde otro dispositivo. Revisá la pantalla de Visitas.']);
        }
    }

    $visit?->participants()->syncWithPivotValues(
        $request->participants ?? [auth()->id()],
        ['role' => 'participant']
    );
    $visit?->participants()->updateExistingPivot(auth()->id(), ['role' => 'creator']);

    /*
    |--------------------------------------------------------------------------
    | PARTICIPANTES (PREPARADO PARA building_visit_participants)
    |--------------------------------------------------------------------------
    |
    | Cuando exista la tabla building_visit_participants,
    | aquí se registrarán el creador y los participantes reales.
    | Ejemplo:
    | $visit->participants()->sync([...]);
    |
    */

    $deliveryNote = DeliveryNote::create([
        'company_id' => auth()->user()->company_id,
        'building_id' => $building->id,
        'building_visit_id' => $visit?->id,
        'user_id' => auth()->id(),
        'work_order_id' => $workOrder?->id, // la orden ya validada (empresa + asignación)
        'assignment_type' => $request->filled('work_order_id') ? 'work_order' : $request->assignment_type,
        'description' => $request->description,
        'elevator_quantity' => $request->elevator_quantity,
        'freight_elevator_quantity' => $request->freight_elevator_quantity,
        'performed' => $request->boolean('performed'),
        'month' => $request->month,
        'year' => $request->year,
        'signature_name' => $request->signature_name,
        'signature' => $request->signature,
        'client_signature' => $request->client_signature,
        'client_signature_name' => $request->client_signature_name,
    ]);


    // Fotos de antes y después cargadas durante el remito: quedan de esta
    // visita y este remito (solo las del técnico, edificio y tipo correctos).
    if ($visit) {
        app(\App\Services\Visits\VisitPhotoService::class)->attach(
            $request->input('photo_batch'), $visit, $deliveryNote, auth()->user(),
            (array) $request->input('photo_notes', []), (array) $request->input('photo_elevators', []),
        );
    }

    if ($request->filled('work_order_id')) {
        $finishedAt = now();

        /*
        |--------------------------------------------------------------------------
        | PARTICIPANTES REALES DE LA WORK ORDER
        |--------------------------------------------------------------------------
        |
        | Los técnicos vienen de work_order_user.
        | Si la orden fue asignada a 2 técnicos,
        | ambos participaron del trabajo.
        |
        */

       $participants = $request->participants ?? [];

        // El técnico que genera el remito siempre participa.
        if (!in_array(auth()->id(), $participants)) {
            $participants[] = auth()->id();
        }

        $workOrder->participants()->syncWithPivotValues(
            $participants,
            ['role' => 'participant']
        );

        $workOrder->participants()->updateExistingPivot(
            auth()->id(),
            ['role' => 'creator']
        );
        // Materiales declarados por el técnico: se registran ANTES de
        // completar la orden, así el descuento de stock (al completarse) los
        // incluye. Mismo material repetido → una sola línea con la suma.
        collect($request->input('materials', []))
            ->groupBy(fn ($row) => (int) $row['stock_item_id'])
            ->each(function ($rows, int $stockItemId) use ($workOrder) {
                $material = new WorkOrderMaterial([
                    'work_order_id' => $workOrder->id,
                    'stock_item_id' => $stockItemId,
                    'quantity' => round($rows->sum(fn ($row) => (float) $row['quantity']), 2),
                ]);
                $material->declared_by = auth()->id();
                $material->save();
            });

        // No completar la orden automáticamente si hay participantes pendientes.
        // La orden debe quedar en progreso hasta que todos confirmen.
        $workOrder->update([
            'status' => 'completed',
            'finished_at' => $finishedAt,
        ]);

        $visit = BuildingVisit::create([
            'company_id' => $workOrder->company_id,
            'building_id' => $workOrder->building_id,
            'user_id' => auth()->id(),
            'source' => 'work_order',
            'visit_type' => 'work_order',
            'work_order_id' => $workOrder->id,
            'assignment_type' => 'work_order',
            'month' => $finishedAt->month,
            'year' => $finishedAt->year,
            'status' => 'done',
            'visited_at' => $finishedAt,
            'started_at' => $workOrder->started_at,
            'finished_at' => $finishedAt,
            'work_type' => $workOrder->type,
            'unit' => $workOrder->unit,
            'notes' => $workOrder->notes,
        ]);

        $visit->participants()->syncWithPivotValues(
            $participants,
            ['role' => 'participant']
        );

        $visit->participants()->updateExistingPivot(
            auth()->id(),
            ['role' => 'creator']
        );



        /*
        |--------------------------------------------------------------------------
        | PARTICIPANTES (PREPARADO PARA building_visit_participants)
        |--------------------------------------------------------------------------
        |
        | Cuando exista la tabla building_visit_participants,
        | aquí se sincronizarán los participantes del work order.
        | Ejemplo:
        | $visit->participants()->sync([...]);
        |
        */
        $deliveryNote->update([
            'building_visit_id' => $visit->id,
        ]);
    }

    // "Trabajo terminado" a los admins (después de responder).
    \App\Jobs\NotifyCompanyAdmins::workCompleted($deliveryNote);

    return redirect()
        ->route('delivery-notes.index', [
            'company' => auth()->user()->company->slug,
        ])
        ->with('success', 'Remito generado correctamente.');
    });

}


    /**
     * Número de visita libre del período (1 o 2), o el motivo por el que no
     * se puede registrar otra. Las visitas sin identidad (duplicados
     * históricos) no cuentan; los remitos viejos sin visita sí ocupan lugar.
     */
    private function periodicSlot(Building $building, string $type, int $month, int $year): int|string
    {
        $eval = \App\Services\Visits\VisitSchedule::load([$building->id], $month, $year, (int) $building->company_id)
            ->evaluate($building->id, $type, $month, $year);
        $period = $eval['period'];

        // Remitos anteriores a las visitas (sin building_visit_id) del mismo período.
        $legacy = DeliveryNote::where('company_id', $building->company_id)
            ->where('building_id', $building->id)
            ->where('assignment_type', $type)
            ->whereNull('building_visit_id')
            ->whereRaw('(year * 12 + month) between ? and ?', [\App\Services\Visits\VisitPeriod::index($period->start), \App\Services\Visits\VisitPeriod::index($period->end)])
            ->count();

        if ($eval['free_slot'] !== null && $eval['visits']->count() + $legacy < $period->expected) {
            return $eval['free_slot'];
        }

        return match (true) {
            $period->expected > 1 => 'Ya se registraron las '.$period->expected.' visitas de este mes.',
            $period->isMultiMonth() => 'La visita de este período ('.$period->label().') ya se registró.',
            default => 'Este remito ya fue generado: el edificio ya tiene el registro de este mes.',
        };
    }

    /**
     * El técnico solo firma el mantenimiento / la inspección mensual de un
     * edificio que tiene asignado para ese tipo de trabajo. Los admins no
     * tienen esta restricción.
     */
    private function ensureAssignedTo(Building $building, string $assignmentType): void
    {
        $user = auth()->user();

        if ($user->isAdmin() || $user->isSuperAdmin()) {
            return;
        }

        abort_unless(
            in_array($assignmentType, ['maintenance', 'inspection'], true)
                && $building->users()
                    ->where('users.id', $user->id)
                    ->wherePivot('type', $assignmentType)
                    ->exists(),
            403,
            'Este edificio no está asignado a vos para este trabajo.'
        );
    }

  public function pdf(Company $company, DeliveryNote $deliveryNote)
{
    // Remito en PDF = remito digital para el cliente (desde Profesional).
    \App\Support\Plans\PlanGuard::for($company)->ensureFeature(\App\Enums\PlanFeature::DigitalDeliveryNotes);

    abort_unless(
        $deliveryNote->company_id === $company->id,
        404
    );

    $user = auth()->user();

    if (!$user->isSuperAdmin()) {

        if ($deliveryNote->company_id !== $user->company_id) {
            abort(404);
        }

       $isOwner = $deliveryNote->user_id === $user->id;

        $isParticipant = $deliveryNote->buildingVisit()
            ->whereHas('participants', function ($query) use ($user) {
                $query->where('users.id', $user->id);
            })
            ->exists();

        abort_unless(
            $user->isAdmin() || $user->isSuperAdmin() || $isOwner || $isParticipant,
            404
        );
    }

    $deliveryNote->load(static::RELATIONS);

    return static::pdfResponse($deliveryNote);
}


public function showPublic(
    Company $company,
    $token
)
{

    // Link público: empresa + token, sin el scope de la sesión (otra cuenta
    // logueada en el mismo navegador no debe romper el link del cliente).
    $deliveryNote = DeliveryNote::withoutGlobalScopes()->where(
        'public_token',
        $token
    )
    ->where(
        'company_id',
        $company->id
    )
    ->firstOrFail();


    $unscoped = fn ($q) => $q->withoutGlobalScopes();

    $deliveryNote->load([
        'company',
        'building' => $unscoped,
        'building.client' => $unscoped,
        'user',
        'workOrder' => $unscoped,
        'workOrder.participants',
        'workOrder.materials' => $unscoped,
        'workOrder.materials.stockItem' => $unscoped,
        'buildingVisit' => $unscoped,
        'buildingVisit.participants',
    ]);


    // El remito firmado es un comprobante para el cliente: no se indexa.
    return response()->view('delivery-notes.show', [
        'deliveryNote' => $deliveryNote,
        'doc' => DeliveryNoteDocument::for($deliveryNote),
        'audience' => 'public',
        'pdfUrl' => route('delivery-notes.public.pdf', ['company' => $company->slug, 'token' => $token]),
    ])->header('X-Robots-Tag', 'noindex, nofollow');
}

/** PDF del enlace público: mismo token, misma empresa (otro token u otra empresa → 404). */
public function publicPdf(Company $company, string $token): Response
{
    $deliveryNote = DeliveryNote::withoutGlobalScopes()->where('public_token', $token)->where('company_id', $company->id)->firstOrFail();

    $unscoped = fn ($q) => $q->withoutGlobalScopes();
    $deliveryNote->load([
        'company',
        'building' => $unscoped,
        'building.client' => $unscoped,
        'user',
        'workOrder' => $unscoped,
        'workOrder.participants',
        'workOrder.materials' => $unscoped,
        'workOrder.materials.stockItem' => $unscoped,
        'buildingVisit' => $unscoped,
        'buildingVisit.participants',
    ]);

    return static::pdfResponse($deliveryNote)->header('X-Robots-Tag', 'noindex, nofollow');
}
}
?>
