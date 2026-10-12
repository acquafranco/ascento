<?php

namespace App\Http\Controllers;

use App\Enums\PlanFeature;
use App\Jobs\NotifyCompanyAdmins;
use App\Models\Building;
use App\Models\BuildingVisit;
use App\Models\Company;
use App\Models\DeliveryNote;
use App\Models\StockItem;
use App\Models\WorkOrder;
use App\Models\WorkOrderMaterial;
use App\Services\Visits\VisitPhotoService;
use App\Services\Visits\VisitSchedule;
use App\Services\Visits\VisitService;
use App\Support\DeliveryNotes\DeliveryNoteDocument;
use App\Support\DeliveryNotes\SignatureImage;
use App\Support\Plans\PlanGuard;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
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

        if (! $user->isAdmin()) {
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
        if (! $user->isAdmin() && ! $user->isSuperAdmin()) {

            $isOwner = $deliveryNote->user_id === $user->id;

            $isParticipant = $deliveryNote->buildingVisit()
                ->whereHas('participants', function ($query) use ($user) {
                    $query->where('users.id', $user->id);
                })
                ->exists();

            if (! $isOwner && ! $isParticipant) {
                abort(404);
            }
        }

        $deliveryNote->load(static::RELATIONS);

        return view('delivery-notes.show', [
            'deliveryNote' => $deliveryNote,
            'doc' => DeliveryNoteDocument::for($deliveryNote),
            'audience' => 'internal',
        ]);
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
        $visits = app(VisitService::class);
        $type = (string) $request->assignment_type;
        $period = VisitSchedule::load([$building->id], $month, $year, (int) $building->company_id)
            ->period($building->id, $type, $month, $year);
        // Visita en curso (abierta desde Visitas, con fotos): el remito la completa.
        $open = $visits->openVisit($building, $type, $month, $year);
        $slot = $open ? (int) $open->slot : $visits->slotFor($building, $type, $month, $year);

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
                    'blocked' => ! $building->hasEquipment()
                        ? 'El edificio no tiene ascensores ni montacargas registrados: no se pueden registrar visitas hasta que la oficina los cargue.'
                        : (is_string($slot) ? $slot : null),
                    'expected' => $period->expected,
                    'period' => $period->isMultiMonth() ? $period->label() : null,
                    'frequency' => $period->frequencyLabel(),
                    'open_visit' => $open,
                ],
                // Identidad del formulario (se conserva si vuelve con errores).
                'submissionToken' => Str::isUuid((string) old('submission_token')) ? (string) old('submission_token') : (string) Str::uuid(),
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
        if (! $workOrder->users()->whereKey($user->id)->exists()) {
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
                // Identidad del formulario: un reenvío del mismo remito no crea otra visita.
                'submission_token' => 'nullable|uuid',
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
                'materials.*.stock_item_id.required' => 'Elegí el material.',
                'materials.*.stock_item_id.exists' => 'Uno de los materiales no existe o no está disponible.',
                'materials.*.quantity.required' => 'Indicá la cantidad de cada material.',
                'materials.*.quantity.gt' => 'La cantidad tiene que ser mayor que cero.',
                'materials.*.quantity.numeric' => 'La cantidad tiene que ser un número.',
                'materials.*.quantity.decimal' => 'La cantidad admite hasta 2 decimales.',
                'materials.*.quantity.max' => 'La cantidad es demasiado grande.',
            ]
        );

        // Firmas: imagen real con trazo (una en blanco o algo que no es una
        // imagen no es una firma), re-codificada como PNG en el servidor.
        $signatures = [];
        foreach (['signature' => 'del técnico', 'client_signature' => 'del cliente'] as $field => $who) {
            if (! $request->filled($field)) {
                continue;
            }
            [$normalized, $error] = SignatureImage::normalize((string) $request->input($field));
            if ($error) {
                throw ValidationException::withMessages([
                    $field => $error === 'empty' ? 'La firma '.$who.' está vacía. Volvé a firmar.' : 'La firma '.$who.' no es válida. Volvé a firmar.',
                ]);
            }
            $signatures[$field] = $normalized;
        }
        $request->merge($signatures);

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
                if (! $workOrder->users()->whereKey(auth()->id())->exists()) {
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
                // Reenvío del MISMO formulario (la respuesta del primero no llegó o se
                // tocó dos veces): ya está guardado, no se crea otra visita. Sin esto,
                // en una frecuencia quincenal el reenvío quedaba como la 2ª visita.
                if ($this->alreadySubmitted($request)) {
                    return $this->submittedResponse();
                }

                // Sin ascensores ni montacargas no hay visitas periódicas.
                if (! $building->hasEquipment()) {
                    return back()->withInput()->withErrors(['general' => 'El edificio no tiene ascensores ni montacargas registrados: no se pueden registrar visitas hasta que la oficina los cargue.']);
                }

                $visitService = app(VisitService::class);

                // Visita en curso del período (abierta desde Visitas para cargar
                // fotos): el remito la completa, no se crea otra.
                if ($open = $visitService->openVisit($building, (string) $assignmentType, (int) $request->month, (int) $request->year, lock: true)) {
                    try {
                        $visit = $visitService->complete($open, auth()->user(), (int) $request->month, (int) $request->year, $request->input('submission_token'));
                    } catch (UniqueConstraintViolationException) {
                        if ($this->alreadySubmitted($request)) {
                            return $this->submittedResponse();
                        }
                        throw new \RuntimeException('No se pudo completar la visita en curso.');
                    }
                }

                if (! $visit) {
                    $slot = $visitService->slotFor($building, (string) $assignmentType, (int) $request->month, (int) $request->year);

                    if (is_string($slot)) {
                        return back()->withInput()->withErrors(['general' => $slot]);
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
                            'submission_token' => $request->input('submission_token'),
                        ]);
                    } catch (UniqueConstraintViolationException) {
                        // El mismo formulario llegó dos veces a la vez: el otro envío ya lo guardó.
                        if ($this->alreadySubmitted($request)) {
                            return $this->submittedResponse();
                        }

                        // Otro técnico registró esa misma visita en el mismo instante.
                        return back()->withInput()->withErrors(['general' => 'Esa visita se acaba de registrar desde otro dispositivo. Revisá la pantalla de Visitas.']);
                    }
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

            // Fotos de la visita (cargadas desde Visitas): quedan también de este remito.
            if ($visit) {
                app(VisitPhotoService::class)->linkToDeliveryNote($visit, $deliveryNote);
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
                if (! in_array(auth()->id(), $participants)) {
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

                // Fotos de la orden (cargadas antes del remito): pasan a su visita y a este remito.
                app(VisitPhotoService::class)->linkToDeliveryNote($visit, $deliveryNote, $workOrder);
            }

            // "Trabajo terminado" a los admins (después de responder).
            NotifyCompanyAdmins::workCompleted($deliveryNote);

            return redirect()
                ->route('delivery-notes.index', [
                    'company' => auth()->user()->company->slug,
                ])
                ->with('success', 'Remito generado correctamente.');
        });

    }

    /** ¿Este formulario de remito (submission_token) ya se guardó, por este mismo usuario? */
    private function alreadySubmitted(Request $request): bool
    {
        $token = $request->input('submission_token');

        return is_string($token) && $token !== '' && BuildingVisit::withoutGlobalScopes()
            ->where('submission_token', $token)
            ->where('company_id', auth()->user()->company_id)
            ->where('user_id', auth()->id())
            ->exists();
    }

    /** Misma respuesta que el primer envío (sin crear nada). */
    private function submittedResponse()
    {
        return redirect()
            ->route('delivery-notes.index', ['company' => auth()->user()->company->slug])
            ->with('success', 'Remito generado correctamente.');
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
        PlanGuard::for($company)->ensureFeature(PlanFeature::DigitalDeliveryNotes);

        abort_unless(
            $deliveryNote->company_id === $company->id,
            404
        );

        $user = auth()->user();

        if (! $user->isSuperAdmin()) {

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
    ) {

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
