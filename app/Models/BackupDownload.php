<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BackupDownload extends Model
{
    public $timestamps = false;

    protected $fillable = [];

    protected $casts = ['downloaded_at' => 'datetime'];

    public function user()
    {
        return $this->belongsTo(User::class)->withTrashed();
    }
}
