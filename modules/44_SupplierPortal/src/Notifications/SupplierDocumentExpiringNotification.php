<?php

declare(strict_types=1);

namespace Modules\SupplierPortal\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells the purchasing staff that a document of a supplier is about to expire or has expired. Stored as a database
 * notification and mailed.
 */
final class SupplierDocumentExpiringNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly string $supplierName,
        public readonly string $documentName,
        public readonly string $expiresOn,
        public readonly int $daysLeft,
        public readonly string $supplierId,
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
        $replace = ['supplier' => $this->supplierName, 'document' => $this->documentName, 'date' => $this->expiresOn, 'days' => (string) $this->daysLeft];
        $key = $this->daysLeft < 0 ? 'expired' : 'expiring';

        return (new MailMessage)
            ->subject(__("supplier_portal.notification.{$key}.subject", $replace))
            ->line(__("supplier_portal.notification.{$key}.body", $replace));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'supplier_document_'.($this->daysLeft < 0 ? 'expired' : 'expiring'),
            'supplier_id' => $this->supplierId,
            'supplier_name' => $this->supplierName,
            'document' => $this->documentName,
            'expires_on' => $this->expiresOn,
            'days_left' => $this->daysLeft,
        ];
    }
}
