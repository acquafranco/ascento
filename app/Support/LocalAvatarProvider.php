<?php

namespace App\Support;

use Filament\AvatarProviders\Contracts\AvatarProvider;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Model;

/**
 * Avatar por defecto del panel: las iniciales en un SVG dentro de la misma
 * página. El proveedor de Filament (ui-avatars.com) mandaba el nombre de
 * cada usuario a un servicio externo en cada pantalla (Etapa 11).
 */
class LocalAvatarProvider implements AvatarProvider
{
    public function get(Model $record): string
    {
        $initials = collect(preg_split('/\s+/u', trim((string) Filament::getNameForDefaultAvatar($record))) ?: [])
            ->map(fn (string $part) => mb_substr((string) preg_replace('/^[^\p{L}\p{N}]+/u', '', $part), 0, 1))
            ->filter()
            ->take(2)
            ->implode('');
        $initials = mb_strtoupper($initials !== '' ? $initials : '?');

        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="64" height="64" viewBox="0 0 64 64">'
            .'<rect width="64" height="64" fill="#09090b"/>'
            .'<text x="50%" y="50%" dy=".1em" fill="#ffffff" font-family="system-ui,-apple-system,sans-serif" font-size="26" font-weight="600" text-anchor="middle" dominant-baseline="middle">'
            .htmlspecialchars($initials, ENT_QUOTES | ENT_XML1, 'UTF-8')
            .'</text></svg>';

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }
}
