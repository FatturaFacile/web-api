<?php

declare(strict_types=1);

namespace App\Infrastructure\Procurement\PurchaseRequisition\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Queued email informing the requester that their Purchase Requisition was rejected.
 */
final class RequisitionRejectedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $requisitionId,
        public readonly string $requisitionNumber,
        public readonly string $requesterName,
        public readonly string $totalAmount,
        public readonly string $currency,
        public readonly int $stepLevel,
        public readonly string $reason,
        string $mailLocale = 'en',
    ) {
        $this->locale($mailLocale);
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('purchase_requisition.mail.rejected_subject', [
                'number' => $this->requisitionNumber,
            ], $this->locale),
        );
    }

    public function content(): Content
    {
        $greeting = e(__('purchase_requisition.mail.rejected_greeting', [
            'requester' => $this->requesterName,
        ], $this->locale));

        $body = e(__('purchase_requisition.mail.rejected_body', [
            'number' => $this->requisitionNumber,
            'amount' => $this->totalAmount,
            'currency' => $this->currency,
            'level' => $this->stepLevel,
        ], $this->locale));

        $reasonLine = e(__('purchase_requisition.mail.rejected_reason', [
            'reason' => $this->reason,
        ], $this->locale));

        return new Content(
            htmlString: "<p>{$greeting}</p><p>{$body}</p><p><strong>{$reasonLine}</strong></p>",
        );
    }
}
