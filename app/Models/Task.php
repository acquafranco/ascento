<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Tarea administrativa de la empresa (Etapa 6, PR 3): responsable,
 * vencimiento, prioridad, estado y recordatorio opcional, con vínculo
 * opcional a un cliente, edificio, ascensor, presupuesto u orden. Se crea,
 * asigna y cambia de estado por TaskService (que valida empresa y
 * responsable). La empresa la pone BelongsToCompany, nunca el pedido.
 */
class Task extends Model
{
    use BelongsToCompany;

    public const STATUSES = ['pending' => 'Pendiente', 'in_progress' => 'En curso', 'done' => 'Completada', 'cancelled' => 'Cancelada'];

    public const OPEN = ['pending', 'in_progress'];

    public const PRIORITIES = ['low' => 'Baja', 'medium' => 'Media', 'high' => 'Alta', 'urgent' => 'Urgente'];

    protected $guarded = ['id', 'company_id', 'created_by', 'completed_by', 'completed_at', 'reminded_at'];

    protected $casts = [
        'due_at' => 'datetime',
        'remind_at' => 'datetime',
        'reminded_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN, true);
    }

    /** Abierta y con el vencimiento pasado. */
    public function isOverdue(): bool
    {
        return $this->isOpen() && $this->due_at !== null && $this->due_at->isPast();
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn($query->qualifyColumn('status'), self::OPEN);
    }

    public function scopeOverdue(Builder $query): Builder
    {
        return $query->open()->whereNotNull('due_at')->where('due_at', '<', now());
    }

    public function assignee()
    {
        return $this->belongsTo(User::class, 'assigned_to')->withTrashed();
    }

    /** Quiénes reciben el recordatorio (vacío: el responsable o quien la creó). */
    public function reminderRecipients()
    {
        return $this->belongsToMany(User::class, 'task_reminder_recipients')->withTimestamps();
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by')->withTrashed();
    }

    public function completer()
    {
        return $this->belongsTo(User::class, 'completed_by')->withTrashed();
    }

    public function client()
    {
        return $this->belongsTo(Client::class)->withTrashed();
    }

    public function building()
    {
        return $this->belongsTo(Building::class)->withTrashed();
    }

    public function elevator()
    {
        return $this->belongsTo(Elevator::class);
    }

    public function quote()
    {
        return $this->belongsTo(Quote::class)->withTrashed();
    }

    public function workOrder()
    {
        return $this->belongsTo(WorkOrder::class)->withTrashed();
    }
}
