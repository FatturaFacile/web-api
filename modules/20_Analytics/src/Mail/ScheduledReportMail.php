<?php

declare(strict_types=1);

namespace Modules\Analytics\Mail;

use Illuminate\Mail\Attachment;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Tells the recipients of a scheduled export that the file is ready, in the language of the schedule. The file itself is
 * attached when it is small enough; the signed link that expires is always there, so a forwarded or archived e-mail stops
 * exposing figures after the validity period when the file was too big to attach.
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
        public readonly ?string $attachmentPath = null,
        public readonly ?string $attachmentName = null,
        public readonly ?string $attachmentMime = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('analytics.mail.subject', ['report' => $this->reportName]));
    }

    public function content(): Content
    {
        $note = $this->truncated ? '<p>'.e(__('analytics.mail.truncated')).'</p>' : '';
        $link = $this->link === '' ? '' : '<p><a href="'.e($this->link).'">'.e(__('analytics.mail.download')).'</a>. '.e(__('analytics.mail.valid_until', ['date' => $this->expiresOn])).'</p>';

        return new Content(htmlString: '<p>'.__('analytics.mail.ready', ['report' => '<strong>'.e($this->reportName).'</strong>', 'rows' => $this->rows]).'</p>'.$link.$note);
    }

    /**
     * @return list<Attachment>
     */
    public function attachments(): array
    {
        if ($this->attachmentPath === null || ! is_file($this->attachmentPath)) {
            return [];
        }

        // The file is read now, because the temporary copy is removed as soon as the delivery ends.
        $contents = (string) file_get_contents($this->attachmentPath);

        return [Attachment::fromData(static fn (): string => $contents, (string) $this->attachmentName)->withMime((string) $this->attachmentMime)];
    }
}
