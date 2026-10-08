<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Telegram\TelegramService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Deja listo el bot de Telegram: valida el token, registra el webhook con la
 * clave secreta y muestra el nombre del bot.
 */
class SetupTelegram extends Command
{
    protected $signature = 'telegram:setup {--send= : Email de un usuario vinculado al que mandarle un mensaje de prueba}';

    protected $description = 'Configura el webhook del bot de Telegram y revisa que todo funcione.';

    public function handle(TelegramService $telegram): int
    {
        if (! TelegramService::isConfigured()) {
            $this->error('✗ Falta TELEGRAM_BOT_TOKEN (creá el bot con @BotFather en Telegram).');

            return self::FAILURE;
        }

        $secret = (string) config('services.telegram.webhook_secret');

        if (! preg_match('/^[A-Za-z0-9_-]{16,256}$/', $secret)) {
            $this->error('✗ TELEGRAM_WEBHOOK_SECRET tiene que tener al menos 16 caracteres (solo letras, números, _ y -).');

            return self::FAILURE;
        }

        $appUrl = rtrim((string) config('app.url'), '/');

        if (! str_starts_with($appUrl, 'https://')) {
            $this->error("✗ APP_URL es \"{$appUrl}\": Telegram solo manda avisos a direcciones https.");

            return self::FAILURE;
        }

        try {
            $me = $telegram->me();
            Cache::forget('telegram:bot-username');
            $this->info('• Bot: @'.($me['username'] ?? '?').' ('.($me['first_name'] ?? '').')');

            $telegram->setWebhook($appUrl.'/api/telegram/webhook', $secret);
            $this->info('• Webhook registrado: '.$appUrl.'/api/telegram/webhook');
        } catch (Throwable $e) {
            $this->error('✗ '.$e->getMessage());

            return self::FAILURE;
        }

        $this->line('• Usuarios con Telegram conectado: '.User::whereNotNull('telegram_chat_id')->count());

        if ($email = $this->option('send')) {
            $user = User::where('email', $email)->first();

            if (! $user?->hasTelegram()) {
                $this->error("✗ {$email} no tiene Telegram conectado.");

                return self::FAILURE;
            }

            $telegram->sendMessage($user->telegram_chat_id, '✅ Prueba de <b>Ascento</b>: los avisos por Telegram funcionan.');
            $this->info("• Mensaje de prueba enviado a {$email}.");
        }

        $this->info('Listo. Los usuarios ya pueden tocar "Conectar Telegram" en Ascento.');

        return self::SUCCESS;
    }
}
