<?php

namespace App\Services\Tasks;

use App\Enums\PermissionArea;
use App\Models\Building;
use App\Models\Client;
use App\Models\Elevator;
use App\Models\Quote;
use App\Models\Task;
use App\Models\User;
use App\Models\WorkOrder;
use App\Notifications\App\TaskNotification;
use App\Services\Notifications\Notifier;
use App\Support\Access\Access;
use App\Support\CompanyContext;
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
 */
class TaskService
{
    public function __construct(private Notifier $notifier) {}

    public function create(User $actor, array $data): Task
    {
        $this->ensureCanManage($actor);
        $companyId = $this->companyOf($actor);
        $clean = $this->validated($companyId, $data);

        $task = new Task($clean);
        $task->forceFill(['created_by' => $actor->id, 'status' => $clean['status'] ?? 'pending']);
        $task->save();
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

        // Recordatorio nuevo: se vuelve a avisar en esa fecha.
        if ($task->isDirty('remind_at')) {
            $task->reminded_at = null;
        }

        $task->save();
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

        Task::withoutGlobalScopes()->open()
            ->whereNotNull('remind_at')->whereNull('reminded_at')->where('remind_at', '<=', now())
            ->orderBy('id')->limit(200)->get(['id'])
            ->each(function (Task $candidate) use (&$sent) {
                $task = DB::transaction(function () use ($candidate) {
                    $task = Task::withoutGlobalScopes()->whereKey($candidate->id)->whereNull('reminded_at')->lockForUpdate()->first();
                    $task?->forceFill(['reminded_at' => now()])->save();

                    return $task;
                });

                $to = $task ? User::find($task->assigned_to ?? $task->created_by) : null;

                if ($to && $this->eligible($to, (int) $task->company_id)) {
                    $sent += (int) (bool) $this->notifier->sendTo($to, new TaskNotification($task, TaskNotification::REMINDER), (int) $task->company_id, mail: false);
                }
            });

        return $sent;
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
