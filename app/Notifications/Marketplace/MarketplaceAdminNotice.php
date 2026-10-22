<?php

namespace App\Notifications\Marketplace;

use App\Models\User;
use App\Notifications\AppNotification;
use Illuminate\Support\Facades\Log;

/** Aviso al equipo de Ascento: proveedor nuevo para aprobar o reporte de una publicación. */
class MarketplaceAdminNotice extends AppNotification
{
    public function __construct(public string $heading, public string $text, public string $target, public string $key) {}

    public function title(): string
    {
        return $this->heading;
    }

    public function body(): string
    {
        return $this->text;
    }

    public function path(): ?string
    {
        return $this->target;
    }

    public function dedupeKey(): ?string
    {
        return $this->key;
    }

    public function icon(): string
    {
        return 'heroicon-o-building-storefront';
    }

    /** A todos los SuperAdmin; si uno falla, el resto igual. */
    public static function toTeam(self $notice): void
    {
        User::where('is_super_admin', true)->get()->each(function ($super) use ($notice) {
            try {
                $super->notifyNow($notice);
            } catch (\Throwable $e) {
                Log::warning('No se pudo avisar al equipo (marketplace)', ['exception' => $e::class]);
            }
        });
    }
}
