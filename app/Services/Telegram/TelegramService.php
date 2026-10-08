<?php

namespace App\Services\Telegram;

use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Bot de Telegram de Ascento: vincular usuarios y mandarles avisos.
 * El token del bot solo vive en el servidor.
 */
class TelegramService
{
    private const LINK_TTL_MINUTES = 15;

    public static function isConfigured(): bool
    {
        return filled(config('services.telegram.bot_token'));
    }

    /** Usuario del bot (sin @), leído de Telegram y cacheado. */
    public function botUsername(): ?string
    {
        if (! static::isConfigured()) {
            return null;
        }

        return Cache::remember('telegram:bot-username', now()->addDay(), fn () => $this->call('getMe')['username'] ?? null);
    }

    /**
     * Link de un solo uso (15 min) para vincular la cuenta: abre el bot y le
     * manda "/start <código>".
     */
    public function linkUrl(User $user): ?string
    {
        $username = $this->botUsername();

        if (! $username) {
            return null;
        }

        $token = Str::random(40);
        Cache::put('telegram:link:'.$token, $user->id, now()->addMinutes(self::LINK_TTL_MINUTES));

        return 'https://t.me/'.$username.'?start='.$token;
    }

    /** Devuelve el usuario dueño del código y lo invalida (un solo uso). */
    public function consumeLinkToken(string $token): ?User
    {
        if (! preg_match('/^[A-Za-z0-9]{40}$/', $token)) {
            return null;
        }

        $userId = Cache::pull('telegram:link:'.$token);

        return $userId ? User::find($userId) : null;
    }

    /**
     * @param  array{0: string, 1: string}|null  $button  [texto, url]
     *
     * @throws TelegramChatGoneException si el usuario bloqueó el bot o borró el chat
     */
    public function sendMessage(string $chatId, string $html, ?array $button = null): void
    {
        $payload = [
            'chat_id' => $chatId,
            'text' => $html,
            'parse_mode' => 'HTML',
            'link_preview_options' => ['is_disabled' => true],
        ];

        if ($button) {
            $payload['reply_markup'] = ['inline_keyboard' => [[['text' => $button[0], 'url' => $button[1]]]]];
        }

        $this->call('sendMessage', $payload);
    }

    public function setWebhook(string $url, string $secret): array
    {
        return $this->call('setWebhook', [
            'url' => $url,
            'secret_token' => $secret,
            'allowed_updates' => ['message'],
            'drop_pending_updates' => true,
        ]);
    }

    public function me(): array
    {
        return $this->call('getMe');
    }

    private function call(string $method, array $payload = []): array
    {
        $token = (string) config('services.telegram.bot_token');

        try {
            $response = Http::acceptJson()
                ->connectTimeout(5)
                ->timeout(10)
                ->post("https://api.telegram.org/bot{$token}/{$method}", $payload);
        } catch (ConnectionException $e) {
            throw new RuntimeException('No se pudo conectar con Telegram.', previous: $e);
        }

        // 403: el usuario bloqueó el bot. 400 "chat not found": el chat ya no existe.
        if ($response->status() === 403
            || ($response->status() === 400 && str_contains((string) $response->json('description'), 'chat not found'))
        ) {
            throw new TelegramChatGoneException((string) $response->json('description'));
        }

        if ($response->failed() || $response->json('ok') !== true) {
            // Nunca loguear la URL: lleva el token del bot.
            throw new RuntimeException('Telegram respondió '.$response->status().': '.$response->json('description'));
        }

        return (array) $response->json('result', []);
    }
}
