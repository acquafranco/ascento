<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Telegram\TelegramService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Webhook del bot de Telegram (POST /api/telegram/webhook).
 *
 * - Valida la clave secreta que Telegram manda en cada aviso.
 * - "/start <código>": vincula el chat con la cuenta dueña del código (un
 *   solo uso, 15 min). Solo chats privados.
 * - "/stop": desvincula ese chat.
 * Siempre responde 200 (si no, Telegram reintenta en loop).
 */
class TelegramWebhookController extends Controller
{
    public function __invoke(Request $request, TelegramService $telegram): JsonResponse
    {
        if (! $this->secretIsValid($request)) {
            return response()->json(['ok' => false], 401);
        }

        $message = $request->input('message');
        $chatId = (string) data_get($message, 'chat.id', '');
        $text = trim((string) data_get($message, 'text', ''));

        if ($chatId === '' || ! preg_match('/^-?\d{1,20}$/', $chatId) || data_get($message, 'chat.type') !== 'private') {
            return response()->json(['ok' => true]);
        }

        try {
            if (preg_match('/^\/start(?:@\w+)?\s+([A-Za-z0-9_-]{1,64})$/', $text, $m)) {
                $this->link($telegram, $chatId, $m[1]);
            } elseif (preg_match('/^\/(stop|salir|desvincular)\b/i', $text)) {
                $this->unlink($telegram, $chatId);
            } else {
                $telegram->sendMessage($chatId, 'Hola 👋 Soy el bot de avisos de <b>Ascento</b>. Para vincular tu cuenta, entrá a Ascento y tocá <b>Conectar Telegram</b>.');
            }
        } catch (Throwable $e) {
            Log::warning('Webhook de Telegram: no se pudo responder', ['error' => $e->getMessage()]);
        }

        return response()->json(['ok' => true]);
    }

    private function link(TelegramService $telegram, string $chatId, string $token): void
    {
        $user = $telegram->consumeLinkToken($token);

        if (! $user || ! $user->canReceivePush()) {
            $telegram->sendMessage($chatId, 'Ese link venció o ya se usó. Volvé a Ascento y tocá <b>Conectar Telegram</b> de nuevo.');

            return;
        }

        // Un chat = una cuenta: si este chat estaba vinculado a otra, se suelta.
        User::where('telegram_chat_id', $chatId)->whereKeyNot($user->id)
            ->update(['telegram_chat_id' => null, 'telegram_linked_at' => null]);

        $user->forceFill(['telegram_chat_id' => $chatId, 'telegram_linked_at' => now()])->saveQuietly();

        $what = $user->isAdmin()
            ? 'cuando un técnico termine un trabajo o cargue un reporte'
            : 'cuando te asignen o modifiquen una orden de trabajo';

        $telegram->sendMessage($chatId, '✅ Listo, <b>'.e($user->name)."</b>. Te voy a avisar acá {$what}.\n\nPara dejar de recibir avisos escribí /stop.");
    }

    private function unlink(TelegramService $telegram, string $chatId): void
    {
        User::where('telegram_chat_id', $chatId)->update(['telegram_chat_id' => null, 'telegram_linked_at' => null]);

        $telegram->sendMessage($chatId, 'Listo, ya no vas a recibir avisos de Ascento acá. Podés volver a conectarlo desde la app cuando quieras.');
    }

    private function secretIsValid(Request $request): bool
    {
        $secret = (string) config('services.telegram.webhook_secret');

        if ($secret === '') {
            // En producción, sin clave no se acepta nada.
            return ! app()->isProduction();
        }

        return hash_equals($secret, (string) $request->header('X-Telegram-Bot-Api-Secret-Token'));
    }
}
