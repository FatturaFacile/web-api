<?php

declare(strict_types=1);

namespace Modules\Associations\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Plain notice of the association to a person. Scalars only, so nothing personal is serialized beyond the text itself.
 */
final class AssociationNoticeMail extends Mailable
{
    use Queueable;

    /**
     * @param  list<string>  $lines
     */
    public function __construct(
        public readonly string $noticeSubject,
        public readonly array $lines,
        public readonly string $senderName,
        public readonly ?string $actionUrl = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->noticeSubject);
    }

    public function content(): Content
    {
        return new Content(markdown: 'associations::mail.notice');
    }
}
