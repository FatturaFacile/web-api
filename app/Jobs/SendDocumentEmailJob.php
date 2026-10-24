<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Mail\DocumentMail;
use App\Models\DocumentEmail;
use App\Models\WorkOrder;
use App\Services\Documents\DocumentSummary;
use App\Services\Pdf\DocumentPdfService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Mail;
use Throwable;

class SendDocumentEmailJob implements ShouldQueue
{
    use Queueable;

    /**
     * A failed delivery is recorded on the email instead of being retried (avoids duplicate mails).
     */
    public int $tries = 1;

    public function __construct(public readonly string $documentEmailId) {}

    public function handle(DocumentSummary $summary, DocumentPdfService $pdfService): void
    {
        $email = DocumentEmail::withoutGlobalScopes()->find($this->documentEmailId);

        if ($email === null || $email->status === 'sent') {
            return;
        }

        try {
            $class = $email->emailable_type;
            $document = $class::withoutGlobalScopes()->findOrFail($email->emailable_id);
            $document->loadMissing($document instanceof WorkOrder ? 'client' : 'contact');

            // The PDF is generated in memory and attached; a rendering failure marks the email as failed.
            $pdf = $pdfService->render($document);

            Mail::to($email->recipient)->send(new DocumentMail($email, $summary->summarize($document), $pdf));
            $email->update(['status' => 'sent', 'sent_at' => now(), 'error' => null, 'attachment_name' => $pdf->filename]);
        } catch (Throwable $e) {
            $email->update(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 1000)]);
        }
    }
}
