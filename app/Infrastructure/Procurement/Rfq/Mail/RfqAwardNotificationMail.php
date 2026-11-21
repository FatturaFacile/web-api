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
 * Queued email notifying winning suppliers of a full or partial RFQ award and generated PO numbers.
 */
final class RfqAwardNotificationMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /**
     * @param  list<string>  $awardedPoNumbers
     */
    public function __construct(
        public readonly string $rfqId,
        public readonly string $rfqNumber,
        public readonly string $rfqTitle,
        public readonly string $buyerCompanyName,
        public readonly string $supplierName,
        public readonly bool $isPartialAward,
        public readonly array $awardedPoNumbers,
        string $mailLocale = 'en',
    ) {
        $this->locale($mailLocale);
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('rfq.mail.award_subject', [
                'number' => $this->rfqNumber,
                'title' => $this->rfqTitle,
            ], $this->locale),
        );
    }

    public function content(): Content
    {
        $greeting = e(__('rfq.mail.award_greeting', ['supplier' => $this->supplierName], $this->locale));
        $bodyKey = $this->isPartialAward ? 'rfq.mail.award_body_partial' : 'rfq.mail.award_body_full';
        $body = e(__($bodyKey, [
            'buyer' => $this->buyerCompanyName,
            'number' => $this->rfqNumber,
            'title' => $this->rfqTitle,
        ], $this->locale));
        $poLine = e(__('rfq.mail.award_po_numbers', [
            'orders' => implode(', ', $this->awardedPoNumbers),
        ], $this->locale));

        return new Content(
            htmlString: "<p>{$greeting}</p><p>{$body}</p><p><strong>{$poLine}</strong></p>",
        );
    }
}
