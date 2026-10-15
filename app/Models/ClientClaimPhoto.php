<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Foto de un reclamo, en disco privado (se sirve por controlador con permiso). */
class ClientClaimPhoto extends Model
{
    protected $guarded = ['*'];
}
