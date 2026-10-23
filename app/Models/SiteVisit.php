<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Visita técnica: un técnico en un edificio, de «Llegué al edificio» a
 * «Finalizar visita y retirarme» (App\Services\WorkOrders\SiteVisits).
 *
 * Agrupa las órdenes (work_orders.site_visit_id) y los remitos
 * (building_visits.site_visit_id) de ese tramo. No es BuildingVisit: aquella es
 * la visita del período (mantenimiento de octubre) o la de una orden, una por
 * remito. Ninguna hora es asignable desde un formulario.
 */
class SiteVisit extends Model
{
    use BelongsToCompany;

    protected $guarded = ['*'];

    protected $casts = [
        'arrived_at' => 'datetime',
        'left_at' => 'datetime',
        'closed_at' => 'datetime',
        'exit_reminded_at' => 'datetime',
    ];

    public function building()
    {
        return $this->belongsTo(Building::class)->withTrashed();
    }

    public function user()
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    public function closedBy()
    {
        return $this->belongsTo(User::class, 'closed_by')->withTrashed();
    }

    public function workOrders()
    {
        return $this->hasMany(WorkOrder::class);
    }

    public function buildingVisits()
    {
        return $this->hasMany(BuildingVisit::class);
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNull('closed_at');
    }

    public function isOpen(): bool
    {
        return $this->closed_at === null;
    }

    /** Remitos firmados en esta visita. */
    public function deliveryNotes()
    {
        return $this->hasManyThrough(DeliveryNote::class, BuildingVisit::class, 'site_visit_id', 'building_visit_id');
    }
}
