<?php

namespace App\Models\Marketplace;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Mensaje de una solicitud de contacto (empresa o profesional). Nunca se edita ni se borra. */
class ServiceContactMessage extends Model
{
    public const COMPANY = 'company';

    public const PROFESSIONAL = 'professional';

    protected $guarded = ['id'];

    public function request(): BelongsTo
    {
        return $this->belongsTo(ServiceContactRequest::class, 'service_contact_request_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function sideLabel(): string
    {
        return $this->side === self::COMPANY ? 'Empresa' : 'Profesional';
    }
}
