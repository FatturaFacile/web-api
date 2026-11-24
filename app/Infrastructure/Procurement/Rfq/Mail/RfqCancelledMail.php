<?php

declare(strict_types=1);

namespace App\Infrastructure\Procurement\Rfq\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Queued email notifying invited suppliers that an open RFQ has been cancelled.
 */
final class RfqCancelledMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $rfqId,
        public readonly string $rfqNumber,
        public readonly string $rfqTitle,
        public readonly string $buyerCompanyName,
        public readonly string $supplierName,
        public readonly ?string $reason = null,
        string $mailLocale = 'en',
    ) {
        $this->locale($mailLocale);
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('rfq.mail.cancelled_subject', [
                'number' => $this->rfqNumber,
                'title' => $this->rfqTitle,
            ], $this->locale),
        );
    }

    public function content(): Content
    {
        $greeting = e(__('rfq.mail.regret_greeting', ['supplier' => $this->supplierName], $this->locale));
        $body = e(__('rfq.mail.cancelled_body', [
            'number' => $this->rfqNumber,
            'title' => $this->rfqTitle,
            'buyer' => $this->buyerCompanyName,
        ], $this->locale));
        $reasonHtml = $this->reason !== null && $this->reason !== ''
            ? '<p>'.e(__('rfq.mail.cancelled_reason', ['reason' => $this->reason], $this->locale)).'</p>'
            : '';

        return new Content(
            htmlString: "<p>{$greeting}</p><p>{$body}</p>{$reasonHtml}",
        );
    }
}
