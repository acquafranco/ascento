<?php

namespace App\Services\Tasks;

use App\Enums\PermissionArea;
use App\Enums\PlanFeature;
use App\Filament\Resources\Tasks\TaskResource;
use App\Models\Building;
use App\Models\Client;
use App\Models\Company;
use App\Models\Elevator;
use App\Models\Quote;
use App\Models\Task;
use App\Models\User;
use App\Models\WorkOrder;
use App\Notifications\App\TaskNotification;
use App\Services\Activity\ActivityLogger;
use App\Services\Notifications\Notifier;
use App\Support\Access\Access;
use App\Support\CompanyContext;
use App\Support\Plans\PlanGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Tareas y recordatorios (Etapa 6, PR 3). Único camino para crearlas,
 * editarlas y cambiarlas de estado, con todo verificado acá:
 *
 * - Empresa: la del usuario (BelongsToCompany); los vínculos (cliente,
 *   edificio, ascensor, presupuesto, orden) tienen que ser de esa empresa.
 * - Responsable: el dueño o un administrativo vigente de la empresa con
 *   «Tareas y recordatorios». Nunca un técnico, el portal ni otra empresa.
 * - Permisos: gestionar tareas para crear, editar, asignar y cambiar el
 *   estado; además, el responsable puede completar o reabrir la suya.
 * - Avisos: al responsable cuando se la asignan (si no se la asignó él
 *   mismo) y el recordatorio programado (una sola vez, ver sendReminders).
 * - Destinatarios del recordatorio: los que elija quien la crea o edita
 *   (mismas reglas que el responsable); sin elegir, el responsable o quien
 *   la creó. Se vuelven a verificar al momento de avisar.
 */
class TaskService
{
    /** Destinatarios del recordatorio por tarea, como máximo. */
    public const MAX_RECIPIENTS = 20;

    public function __construct(private Notifier $notifier) {}

    public function create(User $actor, array $data): Task
    {
        $this->ensureCanManage($actor);
        $companyId = $this->companyOf($actor);
        $clean = $this->validated($companyId, $data);

        $recipients = $this->validatedRecipients($companyId, $data);

        $task = DB::transaction(function () use ($clean, $actor, $recipients) {
            $task = new Task($clean);
            $task->forceFill(['created_by' => $actor->id, 'status' => $clean['status'] ?? 'pending']);
            $task->save();

            if ($recipients !== null) {
                $this->syncRecipients($task, $recipients);
            }

            return $task;
        });
        $this->stampCompletion($task, $actor);

        $this->notifyAssignment($task, $actor);

        return $task;
    }

    public function update(User $actor, Task $task, array $data): Task
    {
        $this->ensureCanManage($actor);
        $this->ensureSameCompany($actor, $task);
        $previousAssignee = $task->assigned_to;

        $task->fill($this->validated((int) $task->company_id, $data));
        $recipients = $this->validatedRecipients((int) $task->company_id, $data);

        // Recordatorio nuevo: se vuelve a avisar en esa fecha (a quienes estén elegidos entonces).
        if ($task->isDirty('remind_at')) {
            $task->reminded_at = null;
        }

        DB::transaction(function () use ($task, $recipients) {
            $task->save();

            // Cambiar destinatarios no reenvía un recordatorio ya enviado:
            // vale para el próximo (o para este, si todavía no salió).
            if ($recipients !== null) {
                $this->syncRecipients($task, $recipients);
            }
        });
        $this->stampCompletion($task, $actor);

        if ((int) $task->assigned_to !== (int) $previousAssignee) {
            $this->notifyAssignment($task, $actor);
        }

        return $task;
    }

    /** Completar, reabrir o cancelar. El responsable puede completar o reabrir la suya. */
    public function setStatus(User $actor, Task $task, string $status): Task
    {
        $this->ensureSameCompany($actor, $task);
        PlanGuard::ensureCurrent(PlanFeature::Tasks);
        abort_unless(array_key_exists($status, Task::STATUSES), 422);

        $own = (int) $task->assigned_to === (int) $actor->id && Access::can($actor, PermissionArea::Tasks);

        abort_unless(Access::can($actor, PermissionArea::Tasks, PermissionArea::MANAGE) || ($own && $status !== 'cancelled'), 403);

        $task->status = $status;
        $task->save();
        $this->stampCompletion($task, $actor);

        return $task;
    }

    /**
     * Recordatorios vencidos (scheduler, cada 5 minutos): uno por tarea y
     * fecha. Se marca antes de avisar (bajo bloqueo): dos corridas a la vez
     * no avisan dos veces. Sin responsable, al que la creó.
     */
    public function sendReminders(): int
    {
        $sent = 0;

        // Solo empresas cuyo plan incluye las tareas: las demás quedan pendientes y avisan si vuelven a tenerlas.
        $companies = Company::query()->get()->filter(fn (Company $c) => PlanGuard::for($c)->allows(PlanFeature::Tasks))->pluck('id');

        Task::withoutGlobalScopes()->open()->whereIn('company_id', $companies)
            ->whereNotNull('remind_at')->whereNull('reminded_at')->where('remind_at', '<=', now())
            ->orderBy('id')->limit(200)->get(['id'])
            ->each(function (Task $candidate) use (&$sent) {
                $task = DB::transaction(function () use ($candidate) {
                    $task = Task::withoutGlobalScopes()->whereKey($candidate->id)->whereNull('reminded_at')->lockForUpdate()->first();
                    $task?->forceFill(['reminded_at' => now()])->save();

                    return $task;
                });

                if (! $task) {
                    return;
                }

                foreach ($this->reminderTargets($task) as $to) {
                    $sent += (int) (bool) $this->notifier->sendTo($to, new TaskNotification($task, TaskNotification::REMINDER, $this->pathFor($to, $task)), (int) $task->company_id, mail: false);
                }
            });

        return $sent;
    }

    /**
     * A quién avisar AHORA: los elegidos que todavía pueden recibirlo (de la
     * empresa, activos, dueño o administrativo vigente con acceso a tareas).
     * Si no se eligió a nadie, como siempre: el responsable o quien la creó.
     * Si se eligió y ninguno puede, no se avisa a otro en su lugar.
     *
     * @return list<User>
     */
    public function reminderTargets(Task $task): array
    {
        $companyId = (int) $task->company_id;
        $chosen = DB::table('task_reminder_recipients')->where('task_id', $task->id)->where('company_id', $companyId)->pluck('user_id');
        $ids = $chosen->isNotEmpty() ? $chosen->all() : array_filter([$task->assigned_to ?? $task->created_by]);

        return User::whereIn('id', $ids)->orderBy('id')->get()
            ->filter(fn (User $user) => $this->eligible($user, $companyId))
            ->values()->all();
    }

    /** Desde el aviso: la tarea, si la puede editar; si no, «Mis tareas» (donde también figura). */
    private function pathFor(User $user, Task $task): string
    {
        $url = Access::can($user, PermissionArea::Tasks, PermissionArea::MANAGE)
            ? TaskResource::getUrl('edit', ['record' => $task], panel: 'ascensores_app')
            : TaskResource::getUrl('index', ['tab' => 'mine'], panel: 'ascensores_app');

        return parse_url($url, PHP_URL_PATH).(($query = parse_url($url, PHP_URL_QUERY)) ? '?'.$query : '');
    }

    /** Ids de usuarios elegidos (null si el formulario no los trae: no se tocan). */
    private function validatedRecipients(int $companyId, array $data): ?array
    {
        if (! array_key_exists('reminder_recipients', $data)) {
            return null;
        }

        $ids = collect((array) ($data['reminder_recipients'] ?? []))->filter(fn ($id) => filled($id))->map(fn ($id) => (int) $id)->unique()->values();

        if ($ids->count() > self::MAX_RECIPIENTS) {
            throw ValidationException::withMessages(['reminder_recipients' => 'Podés elegir hasta '.self::MAX_RECIPIENTS.' personas.']);
        }

        $users = User::withoutGlobalScopes()->whereIn('id', $ids)->get()->keyBy('id');
        foreach ($ids as $id) {
            $user = $users->get($id);
            if (! $user || ! $this->eligible($user, $companyId)) {
                throw ValidationException::withMessages(['reminder_recipients' => 'Solo el dueño o administrativos de la empresa con acceso a tareas.']);
            }
        }

        return $ids->all();
    }

    private function syncRecipients(Task $task, array $ids): void
    {
        $before = $task->reminderRecipients()->pluck('users.id')->map(fn ($id) => (int) $id)->sort()->values()->all();
        $after = collect($ids)->sort()->values()->all();

        if ($before === $after) {
            return;
        }

        $task->reminderRecipients()->sync(collect($ids)->mapWithKeys(fn ($id) => [$id => ['company_id' => $task->company_id]])->all());

        // Quién recibe el recordatorio también queda en la actividad.
        ActivityLogger::record('task.reminder_recipients_updated', $task, ['reminder_recipients' => [
            'from' => User::whereIn('id', $before)->orderBy('name')->pluck('name')->implode(', ') ?: null,
            'to' => User::whereIn('id', $after)->orderBy('name')->pluck('name')->implode(', ') ?: null,
        ]]);
    }

    /** ¿Puede ser responsable de tareas en esa empresa? */
    public function eligible(User $user, int $companyId): bool
    {
        if ((int) $user->company_id !== $companyId || $user->trashed() || $user->isSuperAdmin()) {
            return false;
        }

        return Access::isOwner($user) || (Access::isActiveStaff($user) && Access::can($user, PermissionArea::Tasks));
    }

    /** @return array<int, string> responsables posibles (id => nombre) */
    public function assignableUsers(int $companyId): array
    {
        return User::where('company_id', $companyId)->whereIn('role', ['admin', User::ROLE_STAFF])->where('is_super_admin', false)
            ->orderBy('name')->get()
            ->filter(fn (User $user) => $this->eligible($user, $companyId))
            ->mapWithKeys(fn (User $user) => [$user->id => $user->name])->all();
    }

    private function validated(int $companyId, array $data): array
    {
        $clean = array_intersect_key($data, array_flip([
            'title', 'description', 'priority', 'status', 'due_at', 'remind_at', 'assigned_to',
            'client_id', 'building_id', 'elevator_id', 'quote_id', 'work_order_id',
        ]));

        if (isset($clean['priority']) && ! array_key_exists($clean['priority'], Task::PRIORITIES)) {
            throw ValidationException::withMessages(['priority' => 'Prioridad inválida.']);
        }

        if (isset($clean['status']) && ! array_key_exists($clean['status'], Task::STATUSES)) {
            throw ValidationException::withMessages(['status' => 'Estado inválido.']);
        }

        if (! empty($clean['assigned_to'])) {
            $assignee = User::withoutGlobalScopes()->find($clean['assigned_to']);

            if (! $assignee || ! $this->eligible($assignee, $companyId)) {
                throw ValidationException::withMessages(['assigned_to' => 'El responsable tiene que ser el dueño o un administrativo de la empresa con acceso a tareas.']);
            }
        }

        // Vínculos: de esta empresa (un id ajeno se rechaza, no se ignora).
        foreach ([
            'client_id' => Client::class, 'building_id' => Building::class, 'elevator_id' => Elevator::class,
            'quote_id' => Quote::class, 'work_order_id' => WorkOrder::class,
        ] as $field => $model) {
            if (! empty($clean[$field]) && ! $model::withoutGlobalScopes()->where('company_id', $companyId)->whereKey($clean[$field])->exists()) {
                throw ValidationException::withMessages([$field => 'Ese registro no es de tu empresa.']);
            }
        }

        if (! empty($clean['remind_at']) && ! empty($clean['due_at']) && strtotime((string) $clean['remind_at']) > strtotime((string) $clean['due_at'])) {
            throw ValidationException::withMessages(['remind_at' => 'El recordatorio tiene que ser antes del vencimiento.']);
        }

        return $clean;
    }

    /** Completada: quién y cuándo. Reabierta: se limpia. */
    private function stampCompletion(Task $task, User $actor): void
    {
        if ($task->status === 'done' && $task->completed_at === null) {
            $task->forceFill(['completed_at' => now(), 'completed_by' => $actor->id])->save();
        } elseif ($task->status !== 'done' && $task->completed_at !== null) {
            $task->forceFill(['completed_at' => null, 'completed_by' => null])->save();
        }
    }

    private function notifyAssignment(Task $task, User $actor): void
    {
        $assignee = $task->assigned_to ? User::find($task->assigned_to) : null;

        if ($assignee && (int) $assignee->id !== (int) $actor->id && $task->isOpen()) {
            $this->notifier->sendTo($assignee, new TaskNotification($task, TaskNotification::ASSIGNED), (int) $task->company_id, mail: false);
        }
    }

    private function ensureCanManage(User $actor): void
    {
        abort_unless(Access::can($actor, PermissionArea::Tasks, PermissionArea::MANAGE), 403);
        PlanGuard::ensureCurrent(PlanFeature::Tasks);
    }

    private function ensureSameCompany(User $actor, Task $task): void
    {
        abort_unless((int) $task->company_id === (int) $this->companyOf($actor), 404);
    }

    private function companyOf(User $actor): int
    {
        $id = $actor->isSuperAdmin() ? CompanyContext::currentId() : $actor->company_id;
        abort_unless($id, 403);

        return (int) $id;
    }
}
