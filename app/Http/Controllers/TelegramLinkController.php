<?php

namespace App\Http\Controllers;

use App\Services\Telegram\TelegramService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Conectar / desconectar Telegram desde la app (técnicos) o el panel (admins).
 */
class TelegramLinkController extends Controller
{
    public function connect(Request $request, TelegramService $telegram): RedirectResponse
    {
        $user = $request->user();

        abort_unless($user->canReceivePush() && TelegramService::isConfigured(), 403);

        $url = rescue(fn () => $telegram->linkUrl($user), null, report: true);

        if (! $url) {
            return back()->with('error', 'No pudimos conectar con Telegram. Probá de nuevo en un rato.');
        }

        // Abre Telegram con el bot y el código de un solo uso.
        return redirect()->away($url);
    }

    public function disconnect(Request $request): RedirectResponse
    {
        $request->user()->forceFill(['telegram_chat_id' => null, 'telegram_linked_at' => null])->saveQuietly();

        return back()->with('success', 'Telegram desconectado. Ya no vas a recibir avisos por ahí.');
    }
}
