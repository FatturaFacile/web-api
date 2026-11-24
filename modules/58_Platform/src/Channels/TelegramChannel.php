<?php

declare(strict_types=1);

namespace Modules\Platform\Channels;

use App\Models\User;
use Illuminate\Notifications\Events\NotificationFailed;
use Illuminate\Support\Facades\Http;
use Modules\Platform\Models\NotificationSetting;
use Modules\Platform\Notifications\PlatformNotification;
use Throwable;

/**
 * Telegram bot message to the chat the user linked with /start <token>. A failure is reported as NotificationFailed
 * (and so logged in the delivery register) instead of breaking the other channels.
 */
final class TelegramChannel
{
    public function send(User $notifiable, PlatformNotification $notification): void
    {
        $chatId = NotificationSetting::query()->where('user_id', $notifiable->id)->value('telegram_chat_id');
        $token = config('platform.telegram.bot_token');

        try {
            if (! is_string($chatId) || $chatId === '' || ! is_string($token) || $token === '') {
                throw new \RuntimeException('Telegram is not linked or not configured.');
            }

            $payload = ['chat_id' => $chatId, 'text' => $notification->toText(), 'disable_web_page_preview' => true];

            if ($notification->fullUrl() !== null) {
                $payload['reply_markup'] = ['inline_keyboard' => [[['text' => $notification->title, 'url' => $notification->fullUrl()]]]];
            }

            Http::timeout(10)->post("https://api.telegram.org/bot{$token}/sendMessage", $payload)->throw();
        } catch (Throwable $e) {
            event(new NotificationFailed($notifiable, $notification, self::class, ['message' => $e->getMessage()]));
        }
    }
}
