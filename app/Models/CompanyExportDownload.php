<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Quién descargó una exportación y cuándo (auditoría). */
class CompanyExportDownload extends Model
{
    use BelongsToCompany;

    public $timestamps = false;

    protected $fillable = [];

    protected $casts = ['downloaded_at' => 'datetime'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }
}
