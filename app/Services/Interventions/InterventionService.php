<?php

namespace App\Services\Interventions;

use App\Jobs\NotifyCompanyAdmins;
use App\Models\Building;
use App\Models\OnCallShift;
use App\Models\Report;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\OnCall\OnCallService;
use App\Services\Reports\ReportPhotoService;
use App\Services\WorkOrders\AttendanceTimes;
use App\Services\WorkOrders\SiteVisits;
use App\Support\Access\Access;
use App\Support\WorkOrderLabels;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Intervenciones que inicia el técnico desde Reportes (urgencias y trabajos no
 * programados: reclamos por WhatsApp, por teléfono, del encargado, o algo que
 * detectó en la visita), sin esperar a que la oficina cree la orden.
 *
 * Modelo (integrado con lo que ya existe):
 * - La VISITA es una orden de trabajo del tipo que elige el técnico
 *   (mantenimiento, inspección, reclamo, reparación…), origen 'technician',
 *   creada por el técnico, asignada a él y ya en curso. Desde ahí sigue el
 *   flujo de siempre: «Hacer remito y finalizar» (DeliveryNoteController),
 *   con firma del técnico y del cliente, materiales, fotos y participantes.
 * - Cada PROBLEMA atendido en esa visita es un reporte de tipo 'intervention'
 *   vinculado a la orden: problema informado, por dónde llegó, fotos y, al
 *   firmar el remito, lo que se hizo y cómo quedó. Así una visita con tres
 *   problemas es una orden y un remito (una firma), con tres trabajos
 *   diferenciados. También se pueden sumar trabajos a una orden de la oficina
 *   que el técnico tenga en curso.
 * - Un remito firmado no se reescribe: un trabajo posterior es otra intervención.
 *
 * Reglas: solo dentro de la empresa del técnico; edificios asignados al
 * técnico (o cualquiera de la empresa mientras está de guardia, con la
 * autorización de urgencias o si es el dueño); nunca órdenes de otros
 * técnicos; un reenvío del mismo formulario (idempotency_key) no duplica
 * nada. Abierta durante una guardia, la orden queda vinculada a ese turno.
 */
class InterventionService
{
    /** Tipo de intervención → prioridad del reporte y de la orden. */
    public const KINDS = [
        'urgent' => ['label' => 'Urgencia', 'report' => 'critica', 'order' => 'urgent'],
        'unscheduled' => ['label' => 'Intervención no programada', 'report' => 'media', 'order' => 'medium'],
    ];

    public function __construct(private OnCallService $onCall) {}

    /** ¿Usa la app del técnico y puede abrir intervenciones? (técnicos y el dueño; nunca portal, proveedores ni SuperAdmin) */
    public static function canUse(?User $user): bool
    {
        return $user !== null && $user->company_id !== null && ! $user->isSuperAdmin()
            && in_array($user->role, ['technician', 'admin'], true);
    }

    /** ¿Está de guardia ahora? (cubre edificios que no tiene asignados) */
    public function isOnCallNow(User $user): bool
    {
        return $this->currentShift($user) !== null;
    }

    /**
     * Su turno de guardia vigente AHORA (turno activo y horario de la guardia
     * que cubre este momento), o null. Si está en dos guardias, la primera.
     */
    public function currentShift(User $user): ?OnCallShift
    {
        foreach ($this->onCall->onDutyAt((int) $user->company_id, now()) as $row) {
            if ($shift = $row['shifts']->first(fn ($shift) => (int) $shift->user_id === (int) $user->id)) {
                return $shift;
            }
        }

        return null;
    }

    /**
     * Edificios donde puede abrir una intervención: los activos de su empresa
     * que tiene asignados; todos los de la empresa si es el dueño, si está de
     * guardia en este momento o si tiene la autorización de urgencias
     * (UrgentCoverage). Nunca de otra empresa.
     */
    public function buildingsQuery(User $user): Builder
    {
        $query = Building::withoutGlobalScopes()->where('company_id', $user->company_id)->where('is_active', true);

        if (Access::isOwner($user) || $user->hasUrgentCoverage() || $this->isOnCallNow($user)) {
            return $query;
        }

        return $query->whereIn('id', $user->buildings()->pluck('buildings.id'));
    }

    public function canUseBuilding(User $user, Building $building): bool
    {
        return (int) $building->company_id === (int) $user->company_id
            && $this->buildingsQuery($user)->whereKey($building->id)->exists();
    }

    /**
     * Abre la intervención: crea la orden (la visita) y su primer trabajo.
     *
     * @param  array{building_id: int, work_type: string, elevator_number: string, channel: string, kind: string, description: string, component?: ?string}  $data
     * @param  list<UploadedFile>  $photos
     */
    public function start(User $user, array $data, array $photos, string $idempotencyKey): WorkOrder
    {
        if ($existing = $this->alreadySubmitted($user, $idempotencyKey)) {
            return $existing->workOrder;
        }

        $building = Building::withoutGlobalScopes()->where('company_id', $user->company_id)->whereKey($data['building_id'])->first();
        if (! $building || ! $this->canUseBuilding($user, $building)) {
            throw ValidationException::withMessages(['building_id' => 'No podés registrar intervenciones en ese edificio.']);
        }
        $this->ensureUnit($building, $data['elevator_number']);
        if (! array_key_exists((string) ($data['work_type'] ?? ''), WorkOrderLabels::TYPES)) {
            throw ValidationException::withMessages(['work_type' => 'Elegí el tipo de trabajo.']);
        }
        // Cuándo le avisaron (opcional): lo dice el técnico; nunca se toma la hora de carga como hora del aviso.
        $callAt = filled($data['call_received_at'] ?? null) ? Carbon::parse($data['call_received_at'], config('app.timezone')) : null;
        AttendanceTimes::validateCall($callAt);
        $kind = self::KINDS[$data['kind']];

        try {
            // De guardia ahora: la orden queda en «Trabajos durante la guardia» de ese turno.
            $shift = $this->currentShift($user);

            [$order, $report] = DB::transaction(function () use ($user, $building, $data, $photos, $idempotencyKey, $kind, $shift, $callAt) {
                $order = new WorkOrder;
                $order->forceFill([
                    'company_id' => $user->company_id,
                    'building_id' => $building->id,
                    // Lo elige el técnico (WorkOrderLabels::TYPES, validado en el controlador y acá).
                    'type' => $data['work_type'],
                    'origin' => WorkOrder::ORIGIN_TECHNICIAN,
                    'created_by' => $user->id,
                    'status' => 'in_progress',
                    'priority' => $kind['order'],
                    'unit' => $data['elevator_number'],
                    'component' => $data['component'] ?? null,
                    'started_at' => now(),
                    'on_call_shift_id' => $shift?->id,
                    'call_received_at' => $callAt,
                    'call_source' => $callAt ? 'technician' : null,
                    'call_recorded_by' => $callAt ? $user->id : null,
                    'notes' => $kind['label'].' ('.Report::CHANNELS[$data['channel']].'): '.$data['description'],
                ])->save();
                // Asignada a quien la abrió (las reglas del remito exigen la asignación).
                $order->users()->syncWithoutDetaching([$user->id]);
                $order->participants()->syncWithoutDetaching([$user->id => ['role' => 'creator']]);

                $report = $this->createJob($user, $order, $data, $photos, $idempotencyKey);

                return [$order, $report];
            });
        } catch (UniqueConstraintViolationException) {
            // Dos envíos simultáneos del mismo formulario: el otro ya lo guardó.
            return $this->alreadySubmitted($user, $idempotencyKey)?->workOrder ?? throw ValidationException::withMessages(['general' => 'No se pudo registrar la intervención. Intentá de nuevo.']);
        }

        NotifyCompanyAdmins::reportCreated($report);

        // Abierta estando en el edificio (visita abierta): entra en la visita, sin repetir la llegada.
        app(SiteVisits::class)->attach($user, $order);

        return $order;
    }

    /**
     * Suma otro trabajo a la visita (la orden en curso del técnico, propia o
     * de la oficina), antes de firmar el remito.
     *
     * @param  array{elevator_number: string, channel: string, kind: string, description: string, component?: ?string}  $data
     * @param  list<UploadedFile>  $photos
     */
    public function addJob(User $user, WorkOrder $order, array $data, array $photos, string $idempotencyKey): Report
    {
        if ($existing = $this->alreadySubmitted($user, $idempotencyKey)) {
            return $existing;
        }

        try {
            $report = DB::transaction(function () use ($user, $order, $data, $photos, $idempotencyKey) {
                $locked = WorkOrder::withoutGlobalScopes()->where('company_id', $user->company_id)->lockForUpdate()->findOrFail($order->id);
                if (! $locked->acceptsJobsFrom($user)) {
                    throw ValidationException::withMessages(['general' => 'Ya no se pueden sumar trabajos a esta orden (no está en curso, no está asignada a vos o ya tiene remito).']);
                }
                $this->ensureUnit($locked->building, $data['elevator_number']);

                // Una urgencia sumada a la visita sube la prioridad de la orden (nunca la baja).
                if (($data['kind'] ?? '') === 'urgent' && $locked->priority !== 'urgent') {
                    $locked->forceFill(['priority' => 'urgent'])->save();
                }

                return $this->createJob($user, $locked, $data, $photos, $idempotencyKey);
            });
        } catch (UniqueConstraintViolationException) {
            return $this->alreadySubmitted($user, $idempotencyKey) ?? throw ValidationException::withMessages(['general' => 'No se pudo registrar el trabajo. Intentá de nuevo.']);
        }

        NotifyCompanyAdmins::reportCreated($report);

        return $report;
    }

    private function createJob(User $user, WorkOrder $order, array $data, array $photos, string $idempotencyKey): Report
    {
        $report = new Report([
            'building_id' => $order->building_id,
            'elevator_number' => $data['elevator_number'],
            'component' => $data['component'] ?? null,
            'description' => $data['description'],
            'priority' => self::KINDS[$data['kind']]['report'],
            'status' => 'pendiente',
        ]);
        // Empresa, autor, tipo, orden y origen: nunca del navegador.
        $report->forceFill([
            'company_id' => $order->company_id,
            'user_id' => $user->id,
            'kind' => Report::KIND_INTERVENTION,
            'work_order_id' => $order->id,
            'channel' => $data['channel'],
            'idempotency_key' => $idempotencyKey,
        ])->save();

        app(ReportPhotoService::class)->add($report, $photos);

        return $report;
    }

    private function alreadySubmitted(User $user, string $key): ?Report
    {
        return Report::withoutGlobalScopes()->where('company_id', $user->company_id)->where('user_id', $user->id)
            ->where('idempotency_key', $key)->with('workOrder')->first();
    }

    private function ensureUnit(?Building $building, string $unit): void
    {
        if (! $building || ! in_array($unit, $building->unitLabels(), true)) {
            throw ValidationException::withMessages(['elevator_number' => 'Elegí un equipo de este edificio.']);
        }
    }

    /**
     * Al firmar el remito: lo que se hizo en cada trabajo y cómo quedó. Solo
     * los trabajos de ESTA orden; todos tienen que venir.
     *
     * @param  array<int|string, array{work_done?: mixed, result?: mixed, elevator_state?: mixed, pending_work?: mixed}>  $input
     */
    public function closeJobs(WorkOrder $order, array $input): void
    {
        $jobs = Report::withoutGlobalScopes()->where('work_order_id', $order->id)->where('company_id', $order->company_id)
            ->where('kind', Report::KIND_INTERVENTION)->lockForUpdate()->get();

        $errors = [];
        foreach ($jobs as $i => $job) {
            $row = $input[$job->id] ?? [];
            $done = trim((string) ($row['work_done'] ?? ''));
            $result = (string) ($row['result'] ?? '');
            if (mb_strlen($done) < 3 || mb_strlen($done) > 5000) {
                $errors["jobs.{$job->id}.work_done"] = 'Contá qué hiciste en el trabajo '.($i + 1).'.';
            }
            if (! array_key_exists($result, Report::RESULTS)) {
                $errors["jobs.{$job->id}.result"] = 'Elegí cómo quedó el trabajo '.($i + 1).'.';
            }
            if (! array_key_exists((string) ($row['elevator_state'] ?? ''), Report::ELEVATOR_STATES)) {
                $errors["jobs.{$job->id}.elevator_state"] = 'Elegí en qué estado quedó el equipo del trabajo '.($i + 1).'.';
            }
            $pending = trim((string) ($row['pending_work'] ?? ''));
            if ($result !== 'resolved' && $pending === '') {
                $errors["jobs.{$job->id}.pending_work"] = 'Contá qué quedó pendiente en el trabajo '.($i + 1).'.';
            }
            if (mb_strlen($pending) > 2000) {
                $errors["jobs.{$job->id}.pending_work"] = 'Lo pendiente del trabajo '.($i + 1).' es demasiado largo.';
            }
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        foreach ($jobs as $job) {
            $row = $input[$job->id];
            $result = (string) $row['result'];
            $pending = trim((string) ($row['pending_work'] ?? ''));
            $job->forceFill([
                'work_done' => trim((string) $row['work_done']),
                'result' => $result,
                'elevator_state' => (string) $row['elevator_state'],
                // Resuelto: nada pendiente (aunque se haya escrito algo).
                'pending_work' => $result === 'resolved' || $pending === '' ? null : $pending,
                // Resuelto: listo. Lo demás queda «en revisión» para que la oficina lo siga.
                'status' => $result === 'resolved' ? 'resuelto' : 'en_revision',
            ])->save();
        }
    }

    /** Resumen para el remito cuando el técnico no escribe uno general. */
    public static function summary(WorkOrder $order): string
    {
        return Report::withoutGlobalScopes()->where('work_order_id', $order->id)->where('kind', Report::KIND_INTERVENTION)->orderBy('id')->get()
            ->values()->map(fn (Report $job, int $i) => 'Trabajo '.($i + 1).' ('.$job->elevator_number.'): '.$job->work_done.' — '.$job->resultLabel())
            ->implode("\n");
    }
}
