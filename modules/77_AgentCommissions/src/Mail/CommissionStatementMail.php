<?php

declare(strict_types=1);

namespace Modules\AgentCommissions\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Modules\AgentCommissions\Models\Statement;

/**
 * The commission statement sent to the agent, with the PDF attached.
 */
final class CommissionStatementMail extends Mailable
{
    public function __construct(
        private readonly Statement $statement,
        private readonly string $pdf,
        string $locale,
    ) {
        $this->locale($locale);
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('agent_commissions.statement.mail_subject', ['number' => $this->statement->number], $this->locale));
    }

    public function content(): Content
    {
        return new Content(htmlString: '<p>'.e(__('agent_commissions.statement.mail_body', [
            'number' => $this->statement->number,
            'from' => $this->statement->period_start->format('Y-m-d'),
            'to' => $this->statement->period_end->format('Y-m-d'),
            'net' => number_format((float) $this->statement->net_payable, 2, '.', "'"),
            'currency' => $this->statement->currency,
        ], $this->locale)).'</p>');
    }

    /**
     * @return list<Attachment>
     */
    public function attachments(): array
    {
        return [Attachment::fromData(fn (): string => $this->pdf, 'statement-'.$this->statement->number.'.pdf')->withMime('application/pdf')];
    }
}
