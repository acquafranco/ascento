<?php

namespace App\Models\Marketplace;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/** Especialidad o servicio de «Profesionales y servicios» (la administra el SuperAdmin). */
class ServiceCategory extends Model
{
    protected $fillable = ['name', 'slug', 'description', 'position', 'is_active'];

    protected $casts = ['is_active' => 'boolean', 'position' => 'integer'];

    public function profiles(): BelongsToMany
    {
        return $this->belongsToMany(ServiceProfile::class, 'service_profile_category');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true)->orderBy('position')->orderBy('name');
    }
}
