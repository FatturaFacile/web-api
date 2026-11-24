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
 * WhatsApp Business (Cloud API) message to the number of the user. With `platform.whatsapp.template` set, an approved
 * template (title, body, link as body parameters) is sent, which works outside the 24-hour window; otherwise a text
 * message.
 */
final class WhatsAppChannel
{
    public function send(User $notifiable, PlatformNotification $notification): void
    {
        $number = NotificationSetting::query()->where('user_id', $notifiable->id)->value('whatsapp_number');
        $token = config('platform.whatsapp.token');
        $phoneId = config('platform.whatsapp.phone_number_id');

        try {
            if (! is_string($number) || $number === '' || ! is_string($token) || $token === '' || ! is_string($phoneId) || $phoneId === '') {
                throw new \RuntimeException('WhatsApp is not configured or the user has no number.');
            }

            $template = config('platform.whatsapp.template');
            $message = ['messaging_product' => 'whatsapp', 'to' => ltrim($number, '+')];

            if (is_string($template) && $template !== '') {
                $message += ['type' => 'template', 'template' => [
                    'name' => $template,
                    'language' => ['code' => config('platform.whatsapp.template_language')],
                    'components' => [['type' => 'body', 'parameters' => array_map(
                        static fn (string $text): array => ['type' => 'text', 'text' => $text],
                        [$notification->title, $notification->body ?? '-', $notification->fullUrl() ?? '-'],
                    )]],
                ]];
            } else {
                $message += ['type' => 'text', 'text' => ['body' => $notification->toText(), 'preview_url' => true]];
            }

            Http::withToken($token)->timeout(10)->post("https://graph.facebook.com/v20.0/{$phoneId}/messages", $message)->throw();
        } catch (Throwable $e) {
            event(new NotificationFailed($notifiable, $notification, self::class, ['message' => $e->getMessage()]));
        }
    }
}
