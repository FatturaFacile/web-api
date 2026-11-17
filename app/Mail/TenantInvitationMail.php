<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The invitation to a company, with the link that carries the one-time token.
 */
class TenantInvitationMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $tenantName,
        public readonly string $inviterName,
        public readonly string $role,
        public readonly string $url,
        public readonly string $expiresAt,
    ) {
        $this->afterCommit();
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('access.mail.invitation.subject', ['company' => $this->tenantName, 'app' => config('app.name')]));
    }

    public function content(): Content
    {
        return new Content(markdown: 'emails.access.invitation');
    }
}
