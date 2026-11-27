<?php

declare(strict_types=1);

namespace Modules\SwissEbill\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells accounting that a debtor rejected an eBill in the e-banking, with the bank code and the note of the debtor, so that the
 * invoice can be corrected and sent again. In-app (the client builds the text from the key, swissEbill.notification.rejected) and email.
 */
final class EbillRejectedNotification extends Notification
{
    public function __construct(
        public readonly string $transmissionId,
        public readonly string $invoiceId,
        public readonly string $invoiceNumber,
        public readonly string $customerName,
        public readonly ?string $rejectionCode,
        public readonly ?string $rejectionReason,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $locale = (string) ($notifiable->locale ?? app()->getLocale());

        return (new MailMessage)
            ->subject(__('swiss_ebill.notification.rejected_subject', ['number' => $this->invoiceNumber], $locale))
            ->line(__('swiss_ebill.notification.rejected_line', ['number' => $this->invoiceNumber, 'customer' => $this->customerName], $locale))
            ->line(__('swiss_ebill.notification.rejected_code', ['code' => $this->rejectionCode ?? '-'], $locale))
            ->line(__('swiss_ebill.notification.rejected_reason', ['reason' => $this->rejectionReason ?? '-'], $locale))
            ->line(__('swiss_ebill.notification.rejected_action', [], $locale));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'key' => 'ebill_rejected',
            'module' => 'swiss_ebill',
            'transmission_id' => $this->transmissionId,
            'invoice_id' => $this->invoiceId,
            'invoice_number' => $this->invoiceNumber,
            'customer_name' => $this->customerName,
            'rejection_code' => $this->rejectionCode,
            'rejection_reason' => $this->rejectionReason,
        ];
    }
}
