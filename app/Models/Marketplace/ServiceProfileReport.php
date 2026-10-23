<?php

namespace App\Models\Marketplace;

use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Reporte de abuso de un perfil profesional (lo hace una empresa; lo resuelve el SuperAdmin). */
class ServiceProfileReport extends Model
{
    public const REASONS = [
        'misleading' => 'Información falsa o engañosa',
        'impersonation' => 'Se hace pasar por otra persona o empresa',
        'spam' => 'Spam o publicidad ajena',
        'conduct' => 'Trato inadecuado',
        'other' => 'Otro motivo',
    ];

    public const STATUSES = ['open' => 'Abierto', 'resolved' => 'Resuelto', 'dismissed' => 'Descartado'];

    protected $guarded = ['id'];

    protected $casts = ['resolved_at' => 'datetime'];

    public function profile(): BelongsTo
    {
        return $this->belongsTo(ServiceProfile::class, 'service_profile_id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reasonLabel(): string
    {
        return self::REASONS[$this->reason] ?? $this->reason;
    }
}
