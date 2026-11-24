<?php

declare(strict_types=1);

namespace Modules\Platform\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Modules\Platform\Channels\PushChannel;
use Modules\Platform\Channels\TelegramChannel;
use Modules\Platform\Channels\WhatsAppChannel;

/**
 * The one notification the platform sends on every channel. `url` is a frontend path (deep link): the in-app list, the
 * mobile push and the chat messages all open the linked object from it.
 */
final class PlatformNotification extends Notification
{
    /**
     * @param  list<string>  $channels  channel names of platform.notification_channels
     */
    public function __construct(
        public readonly string $category,
        public readonly string $title,
        public readonly ?string $body = null,
        public readonly ?string $url = null,
        public readonly array $channels = ['database'],
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return array_map(static fn (string $channel): string => match ($channel) {
            'push' => PushChannel::class,
            'telegram' => TelegramChannel::class,
            'whatsapp' => WhatsAppChannel::class,
            default => $channel,
        }, $this->channels);
    }

    public function fullUrl(): ?string
    {
        return $this->url === null ? null : rtrim((string) config('app.frontend_url'), '/').$this->url;
    }

    /**
     * @return array{title: string, message: string|null, url: string|null, category: string}
     */
    public function toArray(object $notifiable): array
    {
        return ['title' => $this->title, 'message' => $this->body, 'url' => $this->url, 'category' => $this->category];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)->subject($this->title)->line($this->body ?? $this->title);

        return $this->fullUrl() === null ? $mail : $mail->action(__('Open'), $this->fullUrl());
    }

    /**
     * Plain text for the chat channels.
     */
    public function toText(): string
    {
        return trim($this->title.($this->body !== null ? "\n".$this->body : '').($this->fullUrl() !== null ? "\n".$this->fullUrl() : ''));
    }
}
