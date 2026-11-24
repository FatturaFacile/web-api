<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Modules\ServiceManagement\Services\Helpdesk\Email\InboundLoopGuard;

/**
 * An e-mail of the helpdesk to a requester: a reply of an agent or an automatic notice. It carries the threading
 * headers (Message-ID, In-Reply-To, References) so mail clients keep the conversation together and replies come back
 * to the right ticket, and the helpdesk loop header. Automatic notices are marked Auto-Submitted.
 */
final class HelpdeskTicketMail extends Mailable
{
    /**
     * @param  list<string>  $references  Message-IDs without angle brackets, oldest first
     */
    public function __construct(
        public readonly string $mailSubject,
        public readonly string $body,
        public readonly string $messageIdentifier,
        public readonly string $ticketNumber,
        public readonly ?string $inReplyTo = null,
        public readonly array $references = [],
        public readonly ?string $fromAddress = null,
        public readonly ?string $fromName = null,
        public readonly bool $isAutomatic = false,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: $this->fromAddress === null ? null : new Address($this->fromAddress, $this->fromName),
            replyTo: $this->fromAddress === null ? [] : [new Address($this->fromAddress, $this->fromName)],
            subject: $this->mailSubject,
        );
    }

    public function headers(): Headers
    {
        $text = [InboundLoopGuard::LoopHeader => $this->ticketNumber];

        if ($this->inReplyTo !== null) {
            $text['In-Reply-To'] = "<{$this->inReplyTo}>";
        }

        if ($this->isAutomatic) {
            $text['Auto-Submitted'] = 'auto-generated';
            $text['X-Auto-Response-Suppress'] = 'All';
        }

        return new Headers(messageId: $this->messageIdentifier, references: $this->references, text: $text);
    }

    public function content(): Content
    {
        return new Content(htmlString: nl2br(e($this->body)));
    }
}
