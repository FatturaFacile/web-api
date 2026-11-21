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
 * Queued email notifying invited suppliers that an RFQ submission deadline has been extended.
 */
final class RfqDeadlineExtendedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $rfqId,
        public readonly string $rfqNumber,
        public readonly string $rfqTitle,
        public readonly string $buyerCompanyName,
        public readonly string $supplierName,
        public readonly string $newDeadlineIso,
        public readonly string $portalUrl,
        string $mailLocale = 'en',
    ) {
        $this->locale($mailLocale);
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('rfq.mail.deadline_extended_subject', [
                'number' => $this->rfqNumber,
                'title' => $this->rfqTitle,
            ], $this->locale),
        );
    }

    public function content(): Content
    {
        $greeting = e(__('rfq.mail.invitation_greeting', ['supplier' => $this->supplierName], $this->locale));
        $body = e(__('rfq.mail.deadline_extended_body', [
            'number' => $this->rfqNumber,
            'title' => $this->rfqTitle,
            'buyer' => $this->buyerCompanyName,
            'deadline' => $this->newDeadlineIso,
        ], $this->locale));
        $cta = e(__('rfq.mail.invitation_cta', [], $this->locale));
        $url = e($this->portalUrl);

        return new Content(
            htmlString: "<p>{$greeting}</p><p>{$body}</p><p><a href=\"{$url}\">{$cta}</a></p>",
        );
    }
}
