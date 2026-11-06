<?php

declare(strict_types=1);

namespace Modules\HumanResources\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The published shifts of one employee. Encrypted on the queue: the payload carries a name and a schedule.
 */
final class ShiftPlanPublishedMail extends Mailable implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable, SerializesModels;

    /**
     * @param  list<array{date: string, name: string, time: string|null}>  $lines
     */
    public function __construct(
        public readonly string $employeeName,
        public readonly string $periodFrom,
        public readonly string $periodTo,
        public readonly array $lines,
    ) {
        $this->afterCommit();
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Piano turni {$this->periodFrom} - {$this->periodTo}");
    }

    public function content(): Content
    {
        return new Content(view: 'hr::mail.shift-plan-published');
    }
}
