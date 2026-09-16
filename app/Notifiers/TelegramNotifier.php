<?php

namespace App\Notifiers;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Sends plain-text messages to the one configured Telegram chat through the Bot API.
 */
class TelegramNotifier
{
    /**
     * @throws RuntimeException when Telegram is not configured
     * @throws RequestException when Telegram rejects the message
     */
    public function send(string $text): void
    {
        $token = config('services.telegram.bot_token');
        $chatId = config('services.telegram.chat_id');

        if (blank($token) || blank($chatId)) {
            throw new RuntimeException('Telegram is not configured: set TELEGRAM_BOT_TOKEN and TELEGRAM_CHAT_ID.');
        }

        Http::timeout(15)
            ->post("https://api.telegram.org/bot{$token}/sendMessage", [
                'chat_id' => $chatId,
                'text' => $text,
            ])
            ->throw();
    }
}
