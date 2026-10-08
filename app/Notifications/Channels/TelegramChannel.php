<?php

namespace App\Notifications\Channels;

use App\Models\User;
use App\Services\Telegram\TelegramChatGoneException;
use App\Services\Telegram\TelegramService;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Canal de notificaciones por Telegram. La notificación implementa
 * toTelegram($notifiable): array{text: string, button?: array{0: string, 1: string}}
 * (text en HTML de Telegram, ya escapado).
 *
 * Un fallo de Telegram nunca rompe nada: se loguea, y si el usuario bloqueó
 * el bot se lo desvincula.
 */
class TelegramChannel
{
    public function __construct(private TelegramService $telegram) {}

    /** Si corresponde agregar este canal para el usuario. */
    public static function enabledFor(object $notifiable): bool
    {
        return $notifiable instanceof User && $notifiable->hasTelegram() && TelegramService::isConfigured();
    }

    public function send(object $notifiable, Notification $notification): void
    {
        if (! static::enabledFor($notifiable) || ! method_exists($notification, 'toTelegram')) {
            return;
        }

        $message = $notification->toTelegram($notifiable);

        try {
            $this->telegram->sendMessage($notifiable->telegram_chat_id, $message['text'], $message['button'] ?? null);
        } catch (TelegramChatGoneException) {
            $notifiable->forceFill(['telegram_chat_id' => null, 'telegram_linked_at' => null])->saveQuietly();
        } catch (Throwable $e) {
            Log::warning('No se pudo enviar el aviso por Telegram', ['user_id' => $notifiable->id, 'error' => $e->getMessage()]);
        }
    }
}
