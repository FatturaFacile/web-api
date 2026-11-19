<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\Contact;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The one-time code of the second sign-in step, required when the tenant security policy asks for it.
 */
class PortalOtpMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly Contact $contact,
        public readonly string $code,
        public readonly int $minutes,
        public readonly string $companyName,
    ) {
        $this->afterCommit();
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('portal.mail.otp.subject', ['company' => $this->companyName]));
    }

    public function content(): Content
    {
        return new Content(markdown: 'emails.portal.otp');
    }
}
