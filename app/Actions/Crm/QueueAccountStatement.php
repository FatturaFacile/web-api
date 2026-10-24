<?php

declare(strict_types=1);

namespace App\Actions\Crm;

use App\Jobs\SendAccountStatementEmail;
use App\Models\Contact;
use App\Models\DocumentEmail;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class QueueAccountStatement
{
    /**
     * Queue the open-items statement of a contact by email (to the contact address unless overridden).
     */
    public function execute(Contact $contact, ?string $to, CarbonInterface $asOf): DocumentEmail
    {
        $recipient = $to ?? $contact->email;

        if ($recipient === null) {
            throw ValidationException::withMessages(['to' => __('documents.no_recipient')]);
        }

        $email = DocumentEmail::create([
            'emailable_type' => $contact->getMorphClass(),
            'emailable_id' => $contact->getKey(),
            'user_id' => Auth::id(),
            'recipient' => $recipient,
            'subject' => __('emails.account_statement.subject', ['date' => $asOf->toDateString()]),
            'status' => 'queued',
        ]);

        SendAccountStatementEmail::dispatch($email->id, $asOf->toDateString());

        return $email;
    }
}
