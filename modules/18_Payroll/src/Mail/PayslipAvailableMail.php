<?php

declare(strict_types=1);

namespace Modules\Payroll\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Says that a payslip is ready. It carries no figure and no attachment. Encrypted on the queue: the payload holds the link.
 */
final class PayslipAvailableMail extends Mailable implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $employeeName,
        public readonly string $companyName,
        public readonly string $period,
        public readonly string $link,
        public readonly int $validityDays,
    ) {
        $this->afterCommit();
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Busta paga {$this->period} disponibile");
    }

    public function content(): Content
    {
        return new Content(view: 'payroll::mail.payslip-available');
    }
}
