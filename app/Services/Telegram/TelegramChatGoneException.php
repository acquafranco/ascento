<?php

namespace App\Services\Telegram;

use RuntimeException;

/** El usuario bloqueó el bot o el chat ya no existe: hay que desvincularlo. */
class TelegramChatGoneException extends RuntimeException {}
