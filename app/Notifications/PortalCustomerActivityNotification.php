<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells the responsible staff that a customer acted in the portal: a quote approved or rejected, a ticket answered.
 * Stored as a database notification and mailed.
 */
class PortalCustomerActivityNotification extends Notification
{
    use Queueable;

    public const string QuoteApproved = 'quote_approved';

    public const string QuoteRejected = 'quote_rejected';

    public const string TicketReplied = 'ticket_replied';

    /**
     * @param  array<string, mixed>  $context  extra data for the record (ids, reason)
     */
    public function __construct(
        public readonly string $activity,
        public readonly string $documentNumber,
        public readonly string $customerName,
        public readonly ?string $reason = null,
        public readonly array $context = [],
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
        $replace = ['number' => $this->documentNumber, 'customer' => $this->customerName];
        $message = (new MailMessage)
            ->subject(__("emails.portal_activity.{$this->activity}.subject", $replace))
            ->line(__("emails.portal_activity.{$this->activity}.body", $replace));

        if ($this->activity === self::QuoteRejected && filled($this->reason)) {
            $message->line(__('emails.portal_activity.quote_rejected.reason', ['reason' => $this->reason]));
        }

        return $message;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'activity' => $this->activity,
            'document_number' => $this->documentNumber,
            'customer_name' => $this->customerName,
            'reason' => $this->reason,
            ...$this->context,
        ];
    }
}
