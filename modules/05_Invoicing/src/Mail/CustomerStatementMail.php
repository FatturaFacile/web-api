<?php

declare(strict_types=1);

namespace Modules\Invoicing\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * The customer statement sent to the customer, with the PDF and the Excel workbook attached.
 */
final class CustomerStatementMail extends Mailable
{
    /**
     * @param  array<string, mixed>  $statement
     */
    public function __construct(
        private readonly array $statement,
        private readonly string $pdf,
        private readonly string $xlsx,
        string $locale,
    ) {
        $this->locale($locale);
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('receivables.statement.mail_subject', ['company' => $this->statement['tenant']['name'], 'date' => $this->statement['period']['to']], $this->locale));
    }

    public function content(): Content
    {
        return new Content(htmlString: '<p>'.e(__('receivables.statement.mail_body', ['customer' => $this->statement['customer']['name'], 'from' => $this->statement['period']['from'], 'to' => $this->statement['period']['to'], 'overdue' => $this->statement['total_overdue'].' '.$this->statement['currency']], $this->locale)).'</p>');
    }

    /**
     * @return list<Attachment>
     */
    public function attachments(): array
    {
        $name = 'statement-'.$this->statement['period']['to'];

        return [
            Attachment::fromData(fn (): string => $this->pdf, $name.'.pdf')->withMime('application/pdf'),
            Attachment::fromData(fn (): string => $this->xlsx, $name.'.xlsx')->withMime('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'),
        ];
    }
}
