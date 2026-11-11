<?php

declare(strict_types=1);

namespace Modules\Analytics\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Tells the recipients of a scheduled export that the file is ready. The data is not in the message: it is behind a
 * signed link that expires, so a forwarded or archived e-mail stops exposing figures after the validity period.
 */
final class ScheduledReportMail extends Mailable
{
    /**
     * Create the message.
     */
    public function __construct(
        public readonly string $reportName,
        public readonly string $link,
        public readonly string $expiresOn,
        public readonly int $rows,
        public readonly bool $truncated,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Scheduled report: '.$this->reportName);
    }

    public function content(): Content
    {
        $note = $this->truncated ? '<p>The report reached the row limit and is incomplete: narrow its period or filters.</p>' : '';

        return new Content(htmlString: '<p>The scheduled report <strong>'.e($this->reportName).'</strong> is ready ('.$this->rows.' rows).</p>'
            .'<p><a href="'.e($this->link).'">Download the file</a>. The link is valid until '.e($this->expiresOn).'.</p>'.$note);
    }
}
