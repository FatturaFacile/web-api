<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\Invoice;
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
        $invoice = $this->reminder->invoice ?? ($this->reminder->invoice_id ? Invoice::withoutGlobalScopes()->find($this->reminder->invoice_id) : null);
        $number = (string) ($invoice?->invoice_number ?? '');

        $subject = __("emails.payment_reminder.subject.level_{$level}", [
            'number' => $number,
        ]);

        if ($number !== '' && ! str_contains($subject, $number)) {
            $subject = rtrim($subject)." {$number}";
        }

        return new Envelope(subject: $subject);
    }

    public function content(): Content
    {
        return new Content(view: 'emails.payment-reminder');
    }
}
