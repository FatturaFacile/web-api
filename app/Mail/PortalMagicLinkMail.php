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
 * The sign-in link of the customer portal. It carries a single-use token that expires within minutes.
 */
class PortalMagicLinkMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly Contact $contact,
        public readonly string $url,
        public readonly int $minutes,
        public readonly string $companyName,
    ) {
        $this->afterCommit();
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('portal.mail.magic_link.subject', ['company' => $this->companyName]));
    }

    public function content(): Content
    {
        return new Content(markdown: 'emails.portal.magic-link');
    }
}
