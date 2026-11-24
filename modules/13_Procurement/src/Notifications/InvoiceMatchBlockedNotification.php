<?php

declare(strict_types=1);

namespace Modules\Procurement\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Tells purchasing and accounts payable that an invoice is blocked for payment because it does not match its order and
 * goods receipt. Stored as a database notification: the texts come from the translation keys of the payload.
 */
final class InvoiceMatchBlockedNotification extends Notification
{
    use Queueable;

    public const string TitleKey = 'invoiceMatching.notification.blockedTitle';

    public const string BodyKey = 'invoiceMatching.notification.blockedBody';

    /**
     * @param  list<string>  $reasons  MatchDiscrepancyReason values
     */
    public function __construct(
        public readonly string $runId,
        public readonly string $passiveInvoiceId,
        public readonly string $invoiceNumber,
        public readonly string $varianceAmount,
        public readonly string $currency,
        public readonly array $reasons,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'title_key' => self::TitleKey,
            'body_key' => self::BodyKey,
            'run_id' => $this->runId,
            'passive_invoice_id' => $this->passiveInvoiceId,
            'invoice_number' => $this->invoiceNumber,
            'variance_amount' => $this->varianceAmount,
            'currency' => $this->currency,
            'reasons' => $this->reasons,
        ];
    }
}
