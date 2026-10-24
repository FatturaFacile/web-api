<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Mail\PaymentReminderMail;
use App\Models\PaymentReminder;
use App\Services\Pdf\DocumentPdfService;
use App\Services\Pdf\RenderedPdf;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Mail;
use Throwable;

class SendPaymentReminderEmail implements ShouldQueue
{
    use Queueable;

    /**
     * A failed delivery is recorded on the reminder instead of being retried (avoids duplicate mails).
     */
    public int $tries = 1;

    public function __construct(public readonly string $reminderId) {}

    public function handle(DocumentPdfService $pdfService): void
    {
        $reminder = PaymentReminder::withoutGlobalScopes()->with(['invoice' => fn ($q) => $q->withoutGlobalScopes(), 'invoice.contact' => fn ($q) => $q->withoutGlobalScopes()])->find($this->reminderId);

        if ($reminder === null || $reminder->status === 'sent') {
            return;
        }

        try {
            Mail::to($reminder->recipient)->send(new PaymentReminderMail($reminder, $this->invoicePdf($pdfService, $reminder)));
            $reminder->update(['status' => 'sent', 'sent_at' => now(), 'error' => null]);
        } catch (Throwable $e) {
            $reminder->update(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 1000)]);
        }
    }

    /**
     * The invoice PDF is a convenience attachment: if it cannot be rendered the reminder is still sent without it.
     */
    private function invoicePdf(DocumentPdfService $pdfService, PaymentReminder $reminder): ?RenderedPdf
    {
        try {
            return $pdfService->render($reminder->invoice);
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }
}
