<?php

declare(strict_types=1);

namespace Modules\CRM\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * A marketing campaign email to one campaign member. Sent only after the consent check of CampaignAudienceService;
 * it always carries the opt-out notice required for marketing communications.
 */
final class CampaignMessageNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $campaignId,
        public readonly string $subjectLine,
        public readonly string $body,
    ) {
        $this->afterCommit();
    }

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->subjectLine)
            ->line($this->body)
            ->line(__('crm.campaign_opt_out_notice'));
    }
}
