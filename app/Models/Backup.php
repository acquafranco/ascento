<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

/**
 * Backup global de Ascento (base + archivos). Sin scope de empresa: lo ve y
 * lo descarga solo el SuperAdmin. Nunca una empresa.
 */
class Backup extends Model
{
    public const REQUESTED = 'requested';

    public const RUNNING = 'running';

    public const COMPLETED = 'completed';

    public const FAILED = 'failed';

    public const STATUSES = [self::REQUESTED => 'En cola', self::RUNNING => 'Generando', self::COMPLETED => 'Completo', self::FAILED => 'Falló'];

    protected $fillable = [];

    protected $casts = [
        'summary' => 'array', 'encrypted' => 'boolean', 'size' => 'integer',
        'started_at' => 'datetime', 'completed_at' => 'datetime', 'verified_at' => 'datetime', 'file_deleted_at' => 'datetime',
    ];

    public function requester()
    {
        return $this->belongsTo(User::class, 'requested_by')->withTrashed();
    }

    public function downloads()
    {
        return $this->hasMany(BackupDownload::class);
    }

    public function hasSafePath(): bool
    {
        return str_starts_with((string) $this->path, config('backup.path').'/') && ! str_contains((string) $this->path, '..');
    }

    public function isDownloadable(): bool
    {
        return $this->status === self::COMPLETED && $this->hasSafePath() && Storage::disk('local')->exists($this->path);
    }

    public function sizeLabel(): string
    {
        return $this->size ? number_format($this->size / 1048576, 1, ',', '.').' MB' : '—';
    }
}
