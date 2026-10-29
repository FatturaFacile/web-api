<?php

declare(strict_types=1);

namespace App\Actions\Documents;

use App\Jobs\SendDocumentEmailJob;
use App\Models\DocumentEmail;
use App\Models\WorkOrder;
use App\Services\Documents\DocumentSummary;
use App\Services\Sales\DocumentEventRecorder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class QueueDocumentEmail
{
    public function __construct(
        private readonly DocumentSummary $summary,
        private readonly DocumentEventRecorder $events,
    ) {}

    /**
     * Record an email for a sales document and queue its delivery. The recipient defaults to the contact's address.
     *
     * @throws ValidationException when no recipient can be determined
     */
    public function execute(Model $document, ?string $to = null, ?string $message = null): DocumentEmail
    {
        $document->loadMissing($document instanceof WorkOrder ? 'client' : 'contact');
        $summary = $this->summary->summarize($document);
        $recipient = $to ?? $summary['contact_email'];

        if ($recipient === null) {
            throw ValidationException::withMessages(['to' => __('documents.no_recipient')]);
        }

        $email = DB::transaction(function () use ($document, $recipient, $message, $summary): DocumentEmail {
            $email = DocumentEmail::create([
                'emailable_type' => $document->getMorphClass(),
                'emailable_id' => $document->getKey(),
                'user_id' => Auth::id(),
                'recipient' => $recipient,
                'subject' => __("emails.document.subject.{$summary['type']}", ['number' => $summary['number']]),
                'message' => $message,
                'status' => 'queued',
            ]);

            $this->events->record($document, 'email_queued', ['document_email_id' => $email->id, 'recipient' => $recipient]);

            return $email;
        });

        SendDocumentEmailJob::dispatch($email->id)->afterCommit();

        return $email;
    }
}
