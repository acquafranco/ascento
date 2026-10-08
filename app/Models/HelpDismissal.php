<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Una ayuda contextual que el usuario ya vio o descartó. */
class HelpDismissal extends Model
{
    public $timestamps = false;

    protected $fillable = ['key', 'dismissed_at'];

    protected $casts = ['dismissed_at' => 'datetime'];
}
