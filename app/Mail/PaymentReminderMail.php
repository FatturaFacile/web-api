<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\PaymentReminder;
use App\Services\Pdf\RenderedPdf;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class PaymentReminderMail extends Mailable
{
    public function __construct(public readonly PaymentReminder $reminder, public readonly ?RenderedPdf $pdf = null) {}

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

    public function envelope(): Envelope
    {
        $level = min(max($this->reminder->level, 1), 3);

        return new Envelope(subject: __("emails.payment_reminder.subject.level_{$level}", [
            'number' => $this->reminder->invoice?->invoice_number,
        ]));
    }

    public function content(): Content
    {
        return new Content(view: 'emails.payment-reminder');
    }
}
