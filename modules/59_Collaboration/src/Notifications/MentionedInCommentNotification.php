<?php

declare(strict_types=1);

namespace Modules\Collaboration\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/**
 * Tells a member that a colleague mentioned them in a comment on a record. Stored as a database notification and mailed.
 */
final class MentionedInCommentNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly string $commentId,
        public readonly string $recordType,
        public readonly string $recordId,
        public readonly string $recordTitle,
        public readonly string $authorName,
        public readonly string $body,
        public readonly string $path,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return filled($notifiable->email ?? null) ? ['database', 'mail'] : ['database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $replace = ['author' => $this->authorName, 'record' => $this->recordTitle];

        return (new MailMessage)
            ->subject(__('collaboration.mention.subject', $replace))
            ->line(__('collaboration.mention.body', $replace))
            ->line(Str::limit($this->body, 300));
    }

    /**
     * @return array<string, string>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'collaboration_mention',
            'comment_id' => $this->commentId,
            'record_type' => $this->recordType,
            'record_id' => $this->recordId,
            'record_title' => $this->recordTitle,
            'author_name' => $this->authorName,
            'excerpt' => Str::limit($this->body, 200),
            'path' => $this->path,
        ];
    }
}
