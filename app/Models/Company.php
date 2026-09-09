<?php

namespace App\Models;

use App\Models\Report;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Company extends Model
{
    use HasFactory;

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
    ];

    public function users()
    {
        return $this->hasMany(User::class);
    }

    public function clients()
    {
        return $this->hasMany(Client::class);
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
    public function onTrial(): bool
    {
        return $this->trial_ends_at !== null
            && $this->trial_ends_at->isFuture();
    }
}
