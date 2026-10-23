<?php

namespace App\Models;

use App\Enums\PlanLimit;
use App\Models\Concerns\ConsumesPlanLimit;
use App\Models\Marketplace\Supplier;
use App\Notifications\ResetPasswordNotification;
use App\Services\Auth\TwoFactorAuthentication;
use App\Support\Access\Access;
use App\Support\Auth\UserEmail;
use App\Support\PhoneNumber;
use App\Support\Portal\PortalAccess;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Log;
use NotificationChannels\WebPush\HasPushSubscriptions;

/**
 * MustVerifyEmail: el registro de una empresa manda el correo de confirmación
 * (evento Registered). No bloquea el acceso (ninguna ruta usa "verified"): el
 * estado se muestra al admin y al SuperAdmin. Los técnicos y clientes del
 * portal no reciben ese correo (los crea la empresa; el portal se activa con
 * su propio enlace).
 */
class User extends Authenticatable implements FilamentUser, MustVerifyEmail
{
    use ConsumesPlanLimit;
    use HasFactory, Notifiable, SoftDeletes;
    use HasPushSubscriptions;

    protected $fillable = [
        'company_id',
        'name',
        'email',
        'password',
        'role',
        'job_type',
        'phone',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'pending_email_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
        // Columna generada (correo normalizado) solo para el índice único.
        'email_key',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        // Segundo factor del SuperAdmin (Etapa 11): cifrados con APP_KEY.
        'two_factor_secret' => 'encrypted',
        'two_factor_recovery_codes' => 'encrypted:array',
        'two_factor_confirmed_at' => 'datetime',
        'pending_email_expires_at' => 'datetime',
        'password' => 'hashed',
        'is_super_admin' => 'boolean',
        // Autorización para registrar urgencias en cualquier edificio de su empresa (UrgentCoverage). No es asignable.
        'urgent_coverage_granted_at' => 'datetime',
        'onboarding_completed_at' => 'datetime',
        'onboarding_step' => 'integer',
        'onboarding_skipped_at' => 'datetime',
        'telegram_linked_at' => 'datetime',
        'portal_invited_at' => 'datetime',
        'portal_activated_at' => 'datetime',
        // Áreas habilitadas a un administrativo (no asignable: ver Access).
        'permissions' => 'array',
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
        // Un correo = una cuenta: se guarda siempre igual (sin espacios
        // alrededor, en minúsculas), venga del formulario que venga.
        // Solo cuando cambia: no reescribe correos guardados antes.
        static::saving(function (User $user) {
            if ($user->isDirty('email') && $user->email !== null) {
                $user->email = UserEmail::normalize($user->email);
            }
            if ($user->isDirty('pending_email') && $user->pending_email !== null) {
                $user->pending_email = UserEmail::normalize($user->pending_email);
            }
        });

        // Usuario del portal con empresa y cliente "de origen": ese es su
        // primer acceso (membresía). Los demás accesos los suma cada empresa.
        static::saved(function (User $user) {
            if ($user->role === self::ROLE_CLIENT && $user->company_id && $user->client_id) {
                $membership = PortalMembership::firstOrNew(['user_id' => $user->id, 'client_id' => $user->client_id]);

                if (! $membership->exists) {
                    $membership->forceFill(['company_id' => $user->company_id, 'invited_at' => $user->portal_invited_at, 'activated_at' => $user->portal_activated_at])->save();
                }
            }
        });

        static::creating(function (User $user) {
            $actor = auth()->user();

            if ($actor && ! $actor->isSuperAdmin()) {
                $user->company_id = $actor->company_id;
                $user->is_super_admin = false;
                // Un dueño o administrativo no crea otro dueño (solo el registro o el SuperAdmin).
                if ($user->role === 'admin') {
                    $user->role = 'technician';
                }
            }
        });

        // Correo nuevo sin confirmar: se manda el enlace para confirmarlo.
        static::updated(function (User $user) {
            if ($user->wasChanged('email') && ! $user->hasVerifiedEmail()) {
                $user->sendVerificationSafely();
            }
        });

        // Nadie puede desactivarse a sí mismo (evita que un admin se deje
        // afuera, y que un técnico "desaparezca" del historial).
        static::deleting(fn (User $user) => auth()->id() !== $user->id);

        static::updating(function (User $user) {
            // Correo nuevo = correo sin confirmar (desde cualquier pantalla:
            // perfil, usuarios del panel, SuperAdmin), salvo que quien guarda
            // marque la confirmación explícitamente (p. ej. activación del portal).
            if ($user->isDirty('email') && ! $user->isDirty('email_verified_at')) {
                $user->email_verified_at = null;
            }

            // El correo cambió por otro camino (el dueño corrige el de un técnico,
            // el SuperAdmin): un pedido pendiente anterior ya no aplica y su enlace
            // no puede pisar esta corrección. La confirmación del propio pedido
            // limpia estos campos en el mismo guardado, así que no entra acá (Etapa 11).
            if ($user->isDirty('email') && $user->pending_email !== null && ! $user->isDirty('pending_email')) {
                $user->pending_email = null;
                $user->pending_email_token = null;
                $user->pending_email_expires_at = null;
            }

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

            // Nadie (salvo el SuperAdmin) cambia a qué proveedor pertenece un usuario.
            if ($user->isDirty('supplier_id')) {
                $user->supplier_id = $user->getOriginal('supplier_id');
            }

            // Dar o quitar el rol de dueño o de administrativo es solo del
            // SuperAdmin (un técnico no se vuelve dueño ni administrativo por un
            // formulario manipulado). Los demás cambios de rol siguen como antes.
            $privileged = ['admin', self::ROLE_STAFF, self::ROLE_SUPPLIER];
            if ($user->isDirty('role') && (in_array($user->role, $privileged, true) || in_array($user->getOriginal('role'), $privileged, true))) {
                $user->role = $user->getOriginal('role') ?? 'technician';
            }

            // Los permisos de un administrativo solo los cambia el dueño de su empresa.
            if ($user->isDirty('permissions') && ! ($actor->isAdmin() && (int) $actor->company_id === (int) $user->company_id && $user->role === self::ROLE_STAFF)) {
                $user->permissions = $user->getOriginal('permissions');
            }
        });
    }

    /** Usuario del portal del cliente (consorcio / administración). */
    public const ROLE_CLIENT = 'client';

    /** Usuario administrativo (Etapa 6): panel con las áreas que habilite el dueño. */
    public const ROLE_STAFF = 'staff';

    /** Usuario de un proveedor de repuestos (marketplace): sin empresa, solo su área /proveedores. */
    public const ROLE_SUPPLIER = 'supplier';

    public function isSupplierUser(): bool
    {
        return $this->role === self::ROLE_SUPPLIER && ! $this->isSuperAdmin();
    }

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }

    public function isStaff(): bool
    {
        return $this->role === self::ROLE_STAFF;
    }

    public function isClientUser(): bool
    {
        return $this->role === self::ROLE_CLIENT;
    }

    /** Cliente al que pertenece un usuario del portal. */
    public function client()
    {
        return $this->belongsTo(Client::class)->withTrashed();
    }

    /** Edificios que el usuario del portal está autorizado a ver. */
    /** Accesos al portal (uno por cliente/empresa que lo autorizó). */
    public function portalMemberships()
    {
        return $this->hasMany(PortalMembership::class);
    }

    public function portalBuildings()
    {
        return $this->belongsToMany(Building::class, 'client_portal_buildings')->withTimestamps();
    }

    public function canAccessPanel(Panel $panel): bool
    {
        if ($panel->getId() !== 'ascensores_app') {
            return false;
        }

        // SuperAdmin, dueño o administrativo con acceso vigente (Access).
        return Access::panelUser($this);
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

        // Cliente del portal: si le queda algún acceso activo (en cualquier
        // empresa). Si no, ningún destino: se cierra la sesión.
        if ($this->isClientUser()) {
            return PortalAccess::currentCompanyId($this) !== null ? route('portal.home') : null;
        }

        // Proveedor de repuestos: su área, mientras su cuenta exista (aprobada o no).
        if ($this->isSupplierUser()) {
            return $this->supplier_id && $this->supplier ? route('suppliers.home') : null;
        }

        if (! $this->company) {
            return null;
        }

        if ($this->isAdmin()) {
            return url('/admin');
        }

        // Administrativo: el panel, o la pantalla que explica por qué no puede entrar.
        if ($this->isStaff()) {
            return Access::isActiveStaff($this) ? url('/admin') : route('staff.blocked');
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
            && (($this->isAdmin() && ! $this->isSuperAdmin() && $this->company_id !== null)
                || Access::isActiveStaff($this));
    }

    public function hasTelegram(): bool
    {
        return filled($this->telegram_chat_id);
    }

    /** ¿Puede registrar dispositivos para recibir push? */
    public function canReceivePush(): bool
    {
        return $this->canReceiveWorkOrderPush() || $this->canReceiveAdminPush() || $this->canReceivePortalPush() || $this->canReceiveSupplierPush();
    }

    /** Proveedores del marketplace: push de sus pedidos y de su cuenta (Notifier valida el proveedor en cada envío). */
    public function canReceiveSupplierPush(): bool
    {
        return ! $this->trashed() && $this->isSupplierUser() && $this->supplier_id !== null;
    }

    /**
     * Clientes del portal: push de lo que la empresa les comparte. Que el
     * plan incluya el portal y el edificio esté autorizado lo valida el
     * middleware del portal (alta) y Notifier (cada envío).
     */
    public function canReceivePortalPush(): bool
    {
        return ! $this->trashed() && $this->isClientUser() && $this->company_id !== null && $this->client_id !== null;
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
     * Ayudas de cada pantalla del panel (HelpTip): el dueño y los
     * administrativos vigentes (solo llegan a las pantallas de sus áreas).
     * La guía de bienvenida sigue siendo del dueño (canUseOnboarding).
     */
    public function canSeeHelpTips(): bool
    {
        return $this->canUseOnboarding() || Access::isActiveStaff($this);
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
     * Introducción breve del panel y botón «?»: el dueño y los
     * administrativos vigentes. El SuperAdmin usa el Centro de ayuda.
     */
    public function canUseHelpIntro(): bool
    {
        return $this->canUseOnboarding() || Access::isActiveStaff($this);
    }

    /**
     * Se abre sola solo la primera vez: hasta que la termine o la descarte
     * (Omitir o cerrar). Abrirla después desde «?» no cambia esto.
     */
    public function shouldAutoStartOnboarding(): bool
    {
        return $this->canUseHelpIntro()
            && $this->onboarding_completed_at === null
            && $this->onboarding_skipped_at === null;
    }

    /**
     * Manda el enlace de confirmación sin cortar lo que se estaba haciendo
     * (alta de un técnico, cambio de correo) si el correo falla.
     */
    public function sendVerificationSafely(): void
    {
        if ($this->hasVerifiedEmail()) {
            return;
        }

        try {
            $this->sendEmailVerificationNotification();
        } catch (\Throwable $e) {
            Log::warning('No se pudo mandar el correo de confirmación', ['user_id' => $this->id, 'exception' => $e::class]);
        }
    }

    /** El SuperAdmin necesita segundo factor (Etapa 11). */
    public function requiresTwoFactor(): bool
    {
        return $this->isSuperAdmin() && TwoFactorAuthentication::enforced();
    }

    /** Mira el valor guardado sin descifrarlo: con otra APP_KEY no explota (se rescata por consola). */
    public function hasConfirmedTwoFactor(): bool
    {
        return $this->two_factor_confirmed_at !== null && filled($this->getAttributes()['two_factor_secret'] ?? null);
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    /**
     * ¿Puede registrar urgencias (intervenciones) en cualquier edificio de su
     * empresa? Solo técnicos activos con la autorización vigente. Se lee de la
     * base en cada pedido: revocarla corta el acceso en el siguiente.
     */
    public function hasUrgentCoverage(): bool
    {
        return $this->role === 'technician' && ! $this->trashed() && $this->urgent_coverage_granted_at !== null;
    }

    public function urgentCoverageGrantedBy()
    {
        return $this->belongsTo(self::class, 'urgent_coverage_granted_by')->withTrashed();
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

    /**
     * Foto de perfil del técnico (Etapa 8.2), por la ruta con permiso; null
     * si no tiene (se muestran sus iniciales). La versión cambia al
     * reemplazarla, así el navegador no muestra la anterior.
     */
    public function photoUrl(): ?string
    {
        return $this->avatar ? route('technician-photos.show', ['user' => $this->id, 'v' => $this->photoVersion()]) : null;
    }

    /** Versión de la foto actual: una URL con otra versión (una foto reemplazada o quitada) ya no responde. */
    public function photoVersion(): ?string
    {
        return $this->avatar ? substr(hash('sha256', $this->avatar), 0, 16) : null;
    }

    /** Iniciales para cuando no hay foto. */
    public function initials(): string
    {
        $words = preg_split('/\s+/', trim((string) $this->name)) ?: [];

        return mb_strtoupper(collect($words)->filter()->take(2)->map(fn ($w) => mb_substr($w, 0, 1))->implode('')) ?: '?';
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
    /*
    |--------------------------------------------------------------------------
    | AYUDAS CONTEXTUALES (una fila por ayuda vista o descartada)
    |--------------------------------------------------------------------------
    */

    public function helpDismissals()
    {
        return $this->hasMany(HelpDismissal::class);
    }

    public function hasSeenHelp(string $key): bool
    {
        return $this->helpDismissals()->where('key', $key)->exists();
    }

    public function dismissHelp(string $key): void
    {
        $this->helpDismissals()->firstOrCreate(['key' => $key], ['dismissed_at' => now()]);
    }

    public function resetHelp(string $key): void
    {
        $this->helpDismissals()->where('key', $key)->delete();
    }

    public function deliveryNotes()
    {
        return $this->hasMany(DeliveryNote::class);
    }

    /**
     * Celular argentino normalizado (549 + área + número). Si no se reconoce
     * (dato viejo o cargado por otro camino) se guardan solo los dígitos, sin
     * inventar prefijos.
     */
    public function setPhoneAttribute($value)
    {
        if (blank($value)) {
            $this->attributes['phone'] = null;

            return;
        }

        $this->attributes['phone'] = PhoneNumber::normalize($value)
            ?? preg_replace('/\D/', '', (string) $value);
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new ResetPasswordNotification($token));
    }

    /** Solo los usuarios operativos ocupan cupo; admins, portal y SuperAdmin no. */
    public function planLimit(): ?PlanLimit
    {
        return match (true) {
            (bool) $this->is_super_admin => null,
            $this->role === self::ROLE_STAFF => PlanLimit::AdminUsers,
            ! in_array($this->role ?? 'technician', ['admin', self::ROLE_CLIENT], true) => PlanLimit::Technicians,
            default => null,
        };
    }
}
