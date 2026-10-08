<?php

namespace App\Models;

use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Notifications\Notifiable;
use App\Notifications\ResetPasswordNotification;
use NotificationChannels\WebPush\HasPushSubscriptions;

class User extends Authenticatable implements FilamentUser
{

    use HasFactory, Notifiable, SoftDeletes;
    use HasPushSubscriptions;


    protected $fillable = [
        'company_id',
        'name',
        'email',
        'password',
        'avatar',
        'role',
        'job_type',
        'phone',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
        'is_super_admin' => 'boolean',
        'onboarding_completed_at' => 'datetime',
        'onboarding_skipped_at' => 'datetime',
        'telegram_linked_at' => 'datetime',
    ];

    /*
    |--------------------------------------------------------------------------
    | CAMPOS DE AUTORIZACIÓN PROTEGIDOS
    |--------------------------------------------------------------------------
    |
    | is_super_admin no es asignable masivamente (ver $fillable). Además,
    | desde una request autenticada por alguien que NO es SuperAdmin:
    | - un usuario nuevo siempre nace en la empresa de quien lo crea;
    | - nadie puede mover un usuario a otra empresa;
    | - nadie puede otorgar ni quitar el flag de SuperAdmin.
    |
    */
    protected static function booted(): void
    {
        static::creating(function (User $user) {
            $actor = auth()->user();

            if ($actor && ! $actor->isSuperAdmin()) {
                $user->company_id = $actor->company_id;
                $user->is_super_admin = false;
            }
        });

        // Nadie puede desactivarse a sí mismo (evita que un admin se deje
        // afuera, y que un técnico "desaparezca" del historial).
        static::deleting(fn (User $user) => auth()->id() !== $user->id);

        static::updating(function (User $user) {
            $actor = auth()->user();

            if (! $actor || $actor->isSuperAdmin()) {
                return;
            }

            if ($user->isDirty('company_id')) {
                $user->company_id = $user->getOriginal('company_id');
            }

            if ($user->isDirty('is_super_admin')) {
                $user->is_super_admin = (bool) $user->getOriginal('is_super_admin');
            }
        });
    }

    public function canAccessPanel(Panel $panel): bool
    {
        if ($panel->getId() !== 'ascensores_app') {
            return false;
        }

        return $this->isSuperAdmin()
            || $this->isAdmin();
    }

        public function isSuperAdmin(): bool
    {
        return (bool) $this->is_super_admin;
    }


    /**
     * Pantalla de inicio según el tipo de usuario: el panel de Filament
     * para admins/SuperAdmin, el dashboard de la empresa para técnicos.
     *
     * Null si la cuenta no tiene ningún lugar válido al que entrar (por
     * ejemplo, un técnico cuya empresa fue eliminada): quien llama debe
     * cerrar la sesión y mandarlo al login, nunca a una pantalla 403.
     */
    public function homeUrl(): ?string
    {
        if ($this->isSuperAdmin()) {
            return url('/admin');
        }

        if (! $this->company) {
            return null;
        }

        if ($this->isAdmin()) {
            return url('/admin');
        }

        return route('dashboard', ['company' => $this->company->slug]);
    }

    /**
     * ¿Puede recibir avisos de administración (trabajo terminado, reportes)?
     * Solo admins de una empresa, no el SuperAdmin.
     */
    public function canReceiveAdminPush(): bool
    {
        return ! $this->trashed()
            && $this->isAdmin()
            && ! $this->isSuperAdmin()
            && $this->company_id !== null;
    }

    public function hasTelegram(): bool
    {
        return filled($this->telegram_chat_id);
    }

    /** ¿Puede registrar dispositivos para recibir push? */
    public function canReceivePush(): bool
    {
        return $this->canReceiveWorkOrderPush() || $this->canReceiveAdminPush();
    }

    /**
     * ¿Puede recibir avisos (push) de órdenes de trabajo? Solo técnicos de
     * una empresa: ni admins ni SuperAdmin, que trabajan desde el panel.
     */
    public function canReceiveWorkOrderPush(): bool
    {
        return ! $this->trashed()
            && ! $this->isSuperAdmin()
            && ! $this->isAdmin()
            && $this->role === 'technician'
            && $this->company_id !== null;
    }

    /**
     * La guía de bienvenida del panel es para administradores de empresa
     * (el SuperAdmin no tiene "Mi empresa" ni opera una empresa propia).
     */
    public function canUseOnboarding(): bool
    {
        return $this->isAdmin() && ! $this->isSuperAdmin() && $this->company_id !== null;
    }

    /**
     * Se abre sola solo la primera vez: hasta que la termine o la omita.
     */
    public function shouldAutoStartOnboarding(): bool
    {
        return $this->canUseOnboarding()
            && $this->onboarding_completed_at === null
            && $this->onboarding_skipped_at === null;
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }



   public function buildings()
    {
        return $this->belongsToMany(Building::class)
            ->withPivot('type')
            ->withTimestamps();
    }
    public function buildingAssignments()
    {
        return $this->belongsToMany(Building::class)
            ->withPivot('type')
            ->withTimestamps();
    }
    public function workOrders()
    {
        return $this->belongsToMany(WorkOrder::class, 'work_order_user')
            ->withTimestamps();
    }

    public function isTechnician(): bool
    {
        return in_array($this->job_type, [
            'maintenance',
            'inspection',
        ]);
    }

        public function buildingVisits()
    {
        return $this->belongsToMany(
            BuildingVisit::class,
            'building_visit_participants'
        );
    }
    public function deliveryNotes()
    {
        return $this->hasMany(DeliveryNote::class);
    }
    public function setPhoneAttribute($value)
{
    if (!$value) {
        $this->attributes['phone'] = null;
        return;
    }

    $phone = preg_replace('/\D/', '', $value);

    $phone = ltrim($phone, '0');

    if (str_starts_with($phone, '549')) {
        $this->attributes['phone'] = $phone;
        return;
    }

    if (str_starts_with($phone, '54')) {
        $phone = substr($phone, 2);
    }

    $this->attributes['phone'] = '549' . $phone;
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function sendPasswordResetNotification($token): void
{
    $this->notify(new ResetPasswordNotification($token));
}

    }
