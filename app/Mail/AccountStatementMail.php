<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\Contact;
use App\Services\Crm\ContactInsights;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class AccountStatementMail extends Mailable
{
    /**
     * @param  array<string, mixed>  $statement  result of {@see ContactInsights::statement()}
     */
    public function __construct(public readonly Contact $contact, public readonly array $statement) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('emails.account_statement.subject', ['date' => $this->statement['as_of']]));
    }

    public function content(): Content
    {
        return new Content(view: 'emails.account-statement');
    }
}
