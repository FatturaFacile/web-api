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
        return new Envelope(subject: __('analytics.mail.subject', ['report' => $this->reportName]));
    }

    public function content(): Content
    {
        $note = $this->truncated ? '<p>'.e(__('analytics.mail.truncated')).'</p>' : '';

        return new Content(htmlString: '<p>'.__('analytics.mail.ready', ['report' => '<strong>'.e($this->reportName).'</strong>', 'rows' => $this->rows]).'</p>'
            .'<p><a href="'.e($this->link).'">'.e(__('analytics.mail.download')).'</a>. '.e(__('analytics.mail.valid_until', ['date' => $this->expiresOn])).'</p>'.$note);
    }
}
