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
 * Queued email notifying non-awarded suppliers that an RFQ has closed and been awarded elsewhere.
 */
final class RfqRegretNotificationMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $rfqId,
        public readonly string $rfqNumber,
        public readonly string $rfqTitle,
        public readonly string $buyerCompanyName,
        public readonly string $supplierName,
        string $mailLocale = 'en',
    ) {
        $this->locale($mailLocale);
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('rfq.mail.regret_subject', [
                'number' => $this->rfqNumber,
                'title' => $this->rfqTitle,
            ], $this->locale),
        );
    }

    public function content(): Content
    {
        $greeting = e(__('rfq.mail.regret_greeting', ['supplier' => $this->supplierName], $this->locale));
        $body = e(__('rfq.mail.regret_body', [
            'number' => $this->rfqNumber,
            'title' => $this->rfqTitle,
            'buyer' => $this->buyerCompanyName,
        ], $this->locale));

        return new Content(
            htmlString: "<p>{$greeting}</p><p>{$body}</p>",
        );
    }
}
