<?php

namespace App\Notifications\App;

use App\Filament\Resources\Clients\ClientResource;
use App\Models\User;
use App\Notifications\AppNotification;

/** Al admin: una persona activó su cuenta del portal (una vez por cuenta). */
class PortalAccountActivatedNotification extends AppNotification
{
    public function __construct(public User $portalUser) {}

    public function title(): string
    {
        return 'Cuenta del portal activada';
    }

    public function body(): string
    {
        return $this->portalUser->name.' ('.$this->portalUser->email.') ya puede ingresar al portal.';
    }

    public function path(): ?string
    {
        return $this->portalUser->client_id
            ? parse_url(ClientResource::getUrl('edit', ['record' => $this->portalUser->client_id], panel: 'ascensores_app'), PHP_URL_PATH)
            : null;
    }

    public function dedupeKey(): ?string
    {
        return 'portal-activated:'.$this->portalUser->id;
    }

    public function icon(): string
    {
        return 'heroicon-o-user-plus';
    }

    public function color(): string
    {
        return 'success';
    }
}
