<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

final class ResidualHoursReportMail extends Mailable
{
    /**
     * @param  array<string, mixed>  $reportData
     */
    public function __construct(
        private readonly string $subjectLine,
        private readonly array $reportData,
        private readonly string $pdfContent,
        private readonly string $filename,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine);
    }

    public function content(): Content
    {
        return new Content(view: 'emails.residual-hours-report', with: $this->reportData);
    }

    /**
     * @return list<Attachment>
     */
    public function attachments(): array
    {
        return [Attachment::fromData(fn (): string => $this->pdfContent, $this->filename)->withMime('application/pdf')];
    }
}
