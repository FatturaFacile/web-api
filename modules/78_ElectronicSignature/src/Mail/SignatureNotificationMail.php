<?php

declare(strict_types=1);

namespace Modules\ElectronicSignature\Mail;

use Illuminate\Mail\Attachment;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * A notification of the signature workflow. The subject, the text and the button label arrive already translated (from
 * the seeded dictionary), so the mailable has no copy of its own.
 */
final class SignatureNotificationMail extends Mailable
{
    /**
     * @param  list<array{name: string, content: string, mime: string}>  $files
     */
    public function __construct(
        private readonly string $mailSubject,
        private readonly string $body,
        private readonly ?string $actionUrl = null,
        private readonly ?string $actionLabel = null,
        private readonly string $footer = '',
        private readonly array $files = [],
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->mailSubject);
    }

    public function content(): Content
    {
        $e = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $paragraphs = implode('', array_map(static fn (string $line): string => '<p style="margin:0 0 12px">'.$e($line).'</p>', array_filter(preg_split('/\R/', $this->body) ?: [], static fn (string $line): bool => trim($line) !== '')));
        $button = $this->actionUrl !== null && $this->actionLabel !== null
            ? '<p style="margin:20px 0"><a href="'.$e($this->actionUrl).'" style="background:#1e407c;color:#ffffff;padding:10px 18px;border-radius:4px;text-decoration:none;display:inline-block">'.$e($this->actionLabel).'</a></p>'
            : '';
        $footer = $this->footer !== '' ? '<p style="margin:24px 0 0;color:#6b7280;font-size:12px">'.$e($this->footer).'</p>' : '';

        return new Content(htmlString: '<div style="font-family:Arial,Helvetica,sans-serif;font-size:14px;color:#111827;max-width:560px">'.$paragraphs.$button.$footer.'</div>');
    }

    /**
     * @return list<Attachment>
     */
    public function attachments(): array
    {
        return array_map(
            static fn (array $file): Attachment => Attachment::fromData(static fn (): string => $file['content'], $file['name'])->withMime($file['mime']),
            $this->files,
        );
    }
}
