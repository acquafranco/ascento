<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Acción del SuperAdmin fuera de una empresa (ver App\Services\Activity\PlatformAudit). */
class PlatformAuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected $casts = ['changes' => 'array', 'created_at' => 'datetime'];
}
