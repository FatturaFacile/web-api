<?php

declare(strict_types=1);

namespace Modules\Visitors\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Tells the host that the visitor is at reception. Encrypted on the queue: the payload holds a name.
 */
final class VisitorArrivedMail extends Mailable implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $visitorName,
        public readonly ?string $visitorCompany,
        public readonly string $badgeNumber,
        public readonly string $companyName,
        public readonly string $arrivalTime,
    ) {
        $this->afterCommit();
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Visitatore in reception: {$this->visitorName}");
    }

    public function content(): Content
    {
        return new Content(view: 'visitors::mail.visitor-arrived');
    }
}
