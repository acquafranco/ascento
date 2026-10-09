<?php

namespace App\Models;

use App\Enums\PlanFeature;
use App\Rules\Cuit;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Company extends Model
{
    /**
     * Slugs que no puede usar una empresa: chocarían con rutas del sistema
     * (la app del técnico vive en /{empresa}/...). Ver routes/web.php.
     */
    public const RESERVED_SLUGS = [
        'admin', 'api', 'broadcasting', 'build', 'confirm-password', 'css', 'dashboard', 'email', 'files', 'filament',
        'forgot-password', 'images', 'js', 'legal', 'livewire', 'login', 'logout', 'manifest.webmanifest', 'mercadopago',
        'notificaciones', 'password', 'portal', 'register', 'reset-password', 'storage', 'sw.js', 'telegram', 'up',
        'verify-email', 'whatsapp',
    ];

    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'business_name',
        'cuit',
        'tax_condition',
        'slug',
        'email',
        'phone',
        'address',
        'city',
        'province',
        'postal_code',
        'activity',
        'gross_income_number',
        'activity_started_at',
        'bank_name',
        'bank_cbu',
        'bank_alias',
        'logo',
        'primary_color',
        'is_active',
        'whatsapp_access_token',
        'whatsapp_phone_number_id',
        'whatsapp_waba_id',
        'whatsapp_business_id',
        'whatsapp_connected',
        'trial_ends_at',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'whatsapp_connected' => 'boolean',
        'trial_ends_at' => 'datetime',
        'activity_started_at' => 'date',
    ];

    /**
     * CUIT siempre con el mismo formato (30-71234567-1), venga de donde venga
     * (registro, Mi empresa, panel del SuperAdmin), para que la unicidad
     * funcione. Vacío → null. Lo que no tiene 11 dígitos (dato viejo) se guarda tal
     * cual. El dígito verificador lo validan el registro y "Mi empresa".
     */
    public function setCuitAttribute(?string $value): void
    {
        $value = trim((string) $value);

        $this->attributes['cuit'] = $value === ''
            ? null
            : (strlen(Cuit::normalize($value)) === 11 ? Cuit::format($value) : $value);
    }

    public function users()
    {
        return $this->hasMany(User::class);
    }

    public function clients()
    {
        return $this->hasMany(Client::class);
    }

    public function buildings()
    {
        return $this->hasMany(Building::class);
    }

    public function workOrders()
    {
        return $this->hasMany(WorkOrder::class);
    }

    public function buildingVisits()
    {
        return $this->hasMany(BuildingVisit::class);
    }

    public function deliveryNotes()
    {
        return $this->hasMany(DeliveryNote::class);
    }

    public function reports(): HasMany
    {
        return $this->hasMany(Report::class);
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    public function subscription(): HasOne
    {
        return $this->hasOne(Subscription::class)
            ->whereIn('status', [
                'authorized',
                'active',
                'trialing',
            ])
            ->whereNotNull('provider_subscription_id')
            ->orderByDesc('id');
    }

    /**
     * La última suscripción, sin importar el status (a diferencia de
     * subscription(), que solo devuelve las "usables" para control de
     * acceso). Usar esta en el panel de admin, donde hace falta ver
     * el estado real incluso si está paused/canceled.
     */
    public function latestSubscription(): HasOne
    {
        return $this->hasOne(Subscription::class)->latestOfMany();
    }

    /**
     * Empresas cuyo acceso (suscripción activa o trial) vence dentro
     * de los próximos $days días. Se usa en el filtro "Vence pronto"
     * del panel de admin.
     */
    public function scopeExpiringSoon($query, int $days = 5)
    {
        $threshold = now()->addDays($days);

        return $query->where(function ($q) use ($threshold) {

            // Tiene una suscripción usable que vence dentro de la ventana.
            $q->whereHas('latestSubscription', function ($sq) use ($threshold) {
                $sq->whereIn('status', ['authorized', 'active', 'trialing'])
                    ->whereNotNull('current_period_end')
                    ->whereBetween('current_period_end', [now(), $threshold]);
            });

            // O nunca tuvo suscripción (sigue en trial gratuito) y el
            // trial vence dentro de la ventana.
            $q->orWhere(function ($q2) use ($threshold) {
                $q2->whereDoesntHave('subscriptions')
                    ->whereNotNull('trial_ends_at')
                    ->whereBetween('trial_ends_at', [now(), $threshold]);
            });
        });
    }

    /**
     * Trial gratuito de 30 días manejado por Ascento (no por Mercado
     * Pago): true mientras trial_ends_at exista y no haya vencido.
     * Lo usa EnsureActiveSubscription para dejar pasar a empresas
     * nuevas sin pedirles tarjeta todavía.
     */
    /**
     * Regla ÚNICA de acceso de la empresa a Ascento (panel, app de
     * técnicos y botones de WhatsApp). La usa EnsureActiveSubscription.
     *
     * - Empresa desactivada por el SuperAdmin (is_active = false): sin acceso.
     * - Si nunca tuvo suscripción, o solo inició el checkout (pending):
     *   el trial gratuito de la app.
     * - Si no, manda la última (Subscription::grantsAccess()):
     *     Mercado Pago authorized → mientras haya un período PAGO vigente
     *       (o 48 h esperando el primer cobro tras autorizar);
     *     past_due (cobro rechazado) → período pago + 5 días de tolerancia;
     *     canceled → hasta que termine el período ya pagado;
     *     manual active → hasta current_period_end;
     *     paused → sin acceso.
     */
    public function hasActiveAccess(): bool
    {
        if (! $this->is_active) {
            return false;
        }

        $subscription = $this->latestSubscription;

        if (! $subscription) {
            return $this->onTrial();
        }

        // Checkout de Mercado Pago iniciado pero sin terminar: no le quita
        // a la empresa los días de prueba ni los días ya pagados que le queden.
        if ($subscription->status === Subscription::PENDING) {
            return $this->onTrial() || $subscription->hasPaidPeriod();
        }

        return $subscription->grantsAccess();
    }

    /** Plan resuelto (memo por instancia). */
    protected ?SubscriptionPlan $resolvedPlan = null;

    /**
     * Plan vigente de la empresa. Nunca devuelve null:
     * - con suscripción: el plan guardado en ella;
     * - en prueba gratis (sin suscripción o con el checkout sin terminar):
     *   el plan de prueba (Profesional);
     * - si el slug no existe (dato viejo): Profesional, el plan principal.
     */
    public function plan(): SubscriptionPlan
    {
        if ($this->resolvedPlan) {
            return $this->resolvedPlan;
        }

        $subscription = $this->latestSubscription;

        $slug = (! $subscription || ($subscription->status === Subscription::PENDING && $this->onTrial()))
            ? SubscriptionPlan::TRIAL_PLAN
            : $subscription->plan;

        return $this->resolvedPlan = SubscriptionPlan::findBySlug($slug)
            ?? SubscriptionPlan::findBySlug(SubscriptionPlan::PROFESIONAL)
            ?? new SubscriptionPlan([
                // Sin planes en la base (instalación nueva sin migrar): sin límites.
                'name' => 'Ascento',
                'slug' => SubscriptionPlan::EMPRESA,
                'feature_keys' => array_map(fn ($f) => $f->value, PlanFeature::cases()),
            ]);
    }

    public function forgetPlan(): void
    {
        $this->resolvedPlan = null;
        $this->unsetRelation('latestSubscription');
    }

    public function onTrial(): bool
    {
        return $this->trial_ends_at !== null
            && $this->trial_ends_at->isFuture();
    }
}
