<?php

declare(strict_types=1);

namespace Modules\Communication\Drivers;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Modules\Communication\Contracts\DeliveryDriver;
use Modules\Communication\DTOs\DeliveryMessage;
use Modules\Communication\Exceptions\DeliveryFailedException;

/**
 * Telegram Bot API, method sendMessage (https://core.telegram.org/bots/api). The recipient is a chat id or @username.
 */
final class TelegramDriver implements DeliveryDriver
{
    /**
     * Driver or resolver name.
     */
    public function name(): string
    {
        return 'telegram';
    }

    /**
     * Send the message.
     */
    public function send(DeliveryMessage $message, array $credentials): array
    {
        $token = $credentials['bot_token'] ?? throw new DeliveryFailedException('The Telegram bot token is not configured.');

        try {
            $response = Http::timeout(15)->asJson()->post('https://api.telegram.org/bot'.strtr(rawurlencode($token), ['%3A' => ':']).'/sendMessage', [
                'chat_id' => $message->recipient,
                'text' => $message->body,
            ]);
        } catch (ConnectionException) {
            throw new DeliveryFailedException('Telegram cannot be reached.');
        }

        if (! $response->successful() || $response->json('ok') !== true) {
            throw new DeliveryFailedException((string) ($response->json('description') ?? 'Telegram refused the message.'));
        }

        return ['status' => 'sent', 'reference' => (string) $response->json('result.message_id')];
    }
}
