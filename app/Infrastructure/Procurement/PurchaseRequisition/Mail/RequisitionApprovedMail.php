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
 * Queued email informing the requester that their Purchase Requisition has been fully approved.
 */
final class RequisitionApprovedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $requisitionId,
        public readonly string $requisitionNumber,
        public readonly string $requesterName,
        public readonly string $totalAmount,
        public readonly string $currency,
        public readonly ?string $comment = null,
        string $mailLocale = 'en',
    ) {
        $this->locale($mailLocale);
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('purchase_requisition.mail.approved_subject', [
                'number' => $this->requisitionNumber,
            ], $this->locale),
        );
    }

    public function content(): Content
    {
        $greeting = e(__('purchase_requisition.mail.approved_greeting', [
            'requester' => $this->requesterName,
        ], $this->locale));

        $body = e(__('purchase_requisition.mail.approved_body', [
            'number' => $this->requisitionNumber,
            'amount' => $this->totalAmount,
            'currency' => $this->currency,
        ], $this->locale));

        $commentHtml = '';
        if ($this->comment !== null && trim($this->comment) !== '') {
            $commentLine = e(__('purchase_requisition.mail.approved_comment', [
                'comment' => $this->comment,
            ], $this->locale));
            $commentHtml = "<p>{$commentLine}</p>";
        }

        return new Content(
            htmlString: "<p>{$greeting}</p><p>{$body}</p>{$commentHtml}",
        );
    }
}
