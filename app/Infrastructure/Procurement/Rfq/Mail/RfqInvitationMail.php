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
 * Queued email inviting a supplier to bid on an RFQ via a cryptographically signed magic link.
 */
final class RfqInvitationMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $rfqId,
        public readonly string $rfqNumber,
        public readonly string $rfqTitle,
        public readonly string $buyerCompanyName,
        public readonly string $supplierName,
        public readonly string $submissionDeadlineIso,
        public readonly string $portalUrl,
        public readonly string $token,
        string $mailLocale = 'en',
    ) {
        $this->locale($mailLocale);
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('rfq.mail.invitation_subject', [
                'number' => $this->rfqNumber,
                'title' => $this->rfqTitle,
            ], $this->locale),
        );
    }

    public function content(): Content
    {
        $greeting = e(__('rfq.mail.invitation_greeting', ['supplier' => $this->supplierName], $this->locale));
        $intro = e(__('rfq.mail.invitation_intro', [
            'buyer' => $this->buyerCompanyName,
            'number' => $this->rfqNumber,
            'title' => $this->rfqTitle,
        ], $this->locale));
        $deadline = e(__('rfq.mail.invitation_deadline', ['deadline' => $this->submissionDeadlineIso], $this->locale));
        $cta = e(__('rfq.mail.invitation_cta', [], $this->locale));
        $outro = e(__('rfq.mail.invitation_outro', [], $this->locale));
        $url = e($this->portalUrl);

        return new Content(
            htmlString: "<p>{$greeting}</p><p>{$intro}</p><p><strong>{$deadline}</strong></p><p><a href=\"{$url}\">{$cta}</a></p><p>{$outro}</p>",
        );
    }
}
