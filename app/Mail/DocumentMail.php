<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\DocumentEmail;
use App\Services\Pdf\RenderedPdf;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class DocumentMail extends Mailable
{
    /**
     * @param  array{type: string, number: string, date: ?string, total: ?float, currency: ?string, contact_name: ?string, contact_email: ?string}  $summary
     */
    public function __construct(
        public readonly DocumentEmail $email,
        public readonly array $summary,
        public readonly ?RenderedPdf $pdf = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->email->subject);
    }

    public function content(): Content
    {
        return new Content(view: 'emails.document');
    }

    /**
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        if ($this->pdf === null) {
            return [];
        }

        return [Attachment::fromData(fn (): string => $this->pdf->content, $this->pdf->filename)->withMime('application/pdf')];
    }
}
