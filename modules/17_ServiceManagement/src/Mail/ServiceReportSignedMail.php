<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Modules\ServiceManagement\FieldService\ServiceReportPdfRenderer;

/**
 * The signed service report sent to the customer contact, in the language of the report, with the PDF attached.
 */
final class ServiceReportSignedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $reportNumber,
        public readonly string $pdfPath,
        public readonly string $reportLocale,
    ) {
        $this->afterCommit();
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: ServiceReportPdfRenderer::inLocale($this->reportLocale, fn (): string => __('field_service.mail.subject', ['number' => $this->reportNumber])));
    }

    public function content(): Content
    {
        $body = ServiceReportPdfRenderer::inLocale($this->reportLocale, fn (): string => __('field_service.mail.body', ['number' => $this->reportNumber]));

        return new Content(htmlString: '<p>'.e($body).'</p>');
    }

    /**
     * @return list<Attachment>
     */
    public function attachments(): array
    {
        return [Attachment::fromStorageDisk('local', $this->pdfPath)->as("{$this->reportNumber}.pdf")->withMime('application/pdf')];
    }
}
