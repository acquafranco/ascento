<?php

namespace App\Models\Marketplace;

use Illuminate\Database\Eloquent\Model;

/** Marca o zona de un perfil, normalizada para filtrar (la sincroniza ServiceProfiles). */
class ServiceProfileTerm extends Model
{
    public const BRAND = 'brand';

    public const AREA = 'area';

    public $timestamps = false;

    protected $guarded = ['id'];
}
