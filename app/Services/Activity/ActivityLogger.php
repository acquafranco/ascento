<?php

namespace App\Services\Activity;

use App\Models\ActivityLog;
use App\Models\Building;
use App\Models\Client;
use App\Models\ClientInteraction;
use App\Models\Elevator;
use App\Models\ElevatorDocument;
use App\Models\MaintenanceService;
use App\Models\Quote;
use App\Models\Receivable;
use App\Models\ReceivablePayment;
use App\Models\StockMovement;
use App\Models\Task;
use App\Models\User;
use App\Models\WorkOrder;
use App\Support\Access\Access;
use App\Support\CompanyContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Registro de acciones sensibles hechas desde el panel (Etapa 6): el dueño,
 * los administrativos y el SuperAdmin dentro de una empresa. Las de los
 * técnicos en su app y las automáticas (scheduler, colas) no se registran acá.
 *
 * Nunca guarda contraseñas, tokens, firmas ni archivos: de cada modelo solo
 * se guardan los campos de FIELDS (lista explícita) y, además, se descarta
 * cualquier clave con nombre sensible. Si el registro falla, la operación
 * sigue (se avisa en el log): no se pierde trabajo por la auditoría.
 */
final class ActivityLogger
{
    /** Modelos auditados → campos que se pueden guardar en «changes». */
    public const FIELDS = [
        Client::class => ['name', 'type', 'contact_person', 'phone', 'email', 'is_active'],
        Building::class => ['name', 'address', 'client_id', 'locality', 'is_active', 'elevator_count', 'freight_elevator_count'],
        Elevator::class => ['manufacturer', 'model', 'serial_number', 'machine_type', 'controller', 'is_active'],
        ElevatorDocument::class => ['type', 'title', 'expires_at'],
        WorkOrder::class => ['type', 'status', 'priority', 'building_id', 'unit'],
        Quote::class => ['title', 'status', 'amount', 'valid_until', 'building_id', 'client_id'],
        MaintenanceService::class => ['description', 'amount', 'frequency', 'status', 'start_date', 'end_date'],
        Receivable::class => ['concept', 'amount', 'due_date'],
        ReceivablePayment::class => ['amount', 'paid_at', 'method'],
        StockMovement::class => ['type', 'quantity', 'stock_item_id', 'reason'],
        Task::class => ['title', 'priority', 'status', 'due_at', 'remind_at', 'assigned_to', 'client_id', 'building_id'],
        ClientInteraction::class => ['client_id', 'type', 'result', 'occurred_at', 'building_id', 'quote_id', 'work_order_id'],
    ];

    /** Nombres que nunca se guardan, aunque alguien los sume a FIELDS por error. */
    private const SENSITIVE = '/pass|token|secret|signature|firma|remember|api[_-]?key|private|credential|path/i';

    public static function record(string $action, ?Model $subject = null, array $changes = [], ?string $label = null): void
    {
        $actor = auth()->user();

        if (! $actor instanceof User || ! Access::panelUser($actor)) {
            return;
        }

        $companyId = $subject?->getAttribute('company_id')
            ?? ($actor->isSuperAdmin() ? CompanyContext::currentId() : $actor->company_id);

        if (! $companyId) {
            return;
        }

        try {
            $log = new ActivityLog;
            $log->forceFill([
                'company_id' => (int) $companyId,
                'user_id' => $actor->id,
                'actor_name' => Str::limit((string) $actor->name, 115, ''),
                'actor_role' => match (true) {
                    $actor->isSuperAdmin() => 'superadmin',
                    $actor->isAdmin() => 'owner',
                    default => 'staff',
                },
                'action' => $action,
                'subject_type' => $subject ? class_basename($subject) : null,
                'subject_id' => $subject?->getKey(),
                'subject_label' => $label !== null ? Str::limit($label, 185, '') : ($subject ? self::labelOf($subject) : null),
                'changes' => self::clean($changes) ?: null,
                'ip' => request()?->ip(),
                'created_at' => now(),
            ])->save();
        } catch (\Throwable $e) {
            Log::warning('No se pudo registrar una acción en la actividad', ['action' => $action, 'exception' => $e::class]);
        }
    }

    /** Cambios de un modelo actualizado: solo campos permitidos, antes → después. */
    public static function changesOf(Model $model): array
    {
        $allowed = self::FIELDS[$model::class] ?? [];
        $changes = [];

        foreach (array_keys($model->getChanges()) as $field) {
            if (in_array($field, $allowed, true)) {
                $changes[$field] = ['from' => self::scalar($model->getOriginal($field)), 'to' => self::scalar($model->getAttribute($field))];
            }
        }

        return $changes;
    }

    /** Datos permitidos de un modelo (al crearlo o borrarlo). */
    public static function snapshotOf(Model $model): array
    {
        return collect(self::FIELDS[$model::class] ?? [])
            ->mapWithKeys(fn (string $field) => [$field => self::scalar($model->getAttribute($field))])
            ->filter(fn ($value) => $value !== null && $value !== '')
            ->all();
    }

    private static function clean(array $changes): array
    {
        $clean = [];

        foreach ($changes as $key => $value) {
            if (preg_match(self::SENSITIVE, (string) $key)) {
                continue;
            }

            $clean[$key] = is_array($value) ? self::clean($value) : self::scalar($value);
        }

        return $clean;
    }

    private static function scalar(mixed $value): mixed
    {
        return match (true) {
            $value instanceof \DateTimeInterface => $value->format('Y-m-d H:i'),
            $value instanceof \BackedEnum => $value->value,
            is_bool($value), is_int($value), is_float($value), $value === null => $value,
            is_scalar($value) => Str::limit((string) $value, 190),
            default => null,
        };
    }

    private static function labelOf(Model $subject): ?string
    {
        foreach (['name', 'title', 'label', 'concept'] as $field) {
            if (filled($subject->getAttribute($field))) {
                return Str::limit((string) $subject->getAttribute($field), 185, '');
            }
        }

        return class_basename($subject).' #'.$subject->getKey();
    }
}
