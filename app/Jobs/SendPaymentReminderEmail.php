<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Mail\PaymentReminderMail;
use App\Models\PaymentReminder;
use App\Models\Tenant;
use App\Services\Pdf\DocumentPdfService;
use App\Services\Pdf\RenderedPdf;
use App\Services\SwissQrBill\GeneratedQrBill;
use App\Services\SwissQrBill\SwissQrBillService;
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

    public function __construct(public readonly string $reminderId)
    {
        $this->afterCommit();
    }

    public function handle(DocumentPdfService $pdfService, ?SwissQrBillService $qrBillService = null): void
    {
        $reminder = PaymentReminder::withoutGlobalScopes()->with(['invoice' => fn ($q) => $q->withoutGlobalScopes(), 'invoice.contact' => fn ($q) => $q->withoutGlobalScopes()])->find($this->reminderId);

        if ($reminder === null || $reminder->status === 'sent') {
            return;
        }

        try {
            $qrBill = $this->swissQrBill($qrBillService ?? app(SwissQrBillService::class), $reminder);
            Mail::to($reminder->recipient)
                ->locale($reminder->invoice?->contact?->document_language ?: app()->getLocale())
                ->send(new PaymentReminderMail($reminder, $this->invoicePdf($pdfService, $reminder), $qrBill));
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

    /**
     * Generate the Swiss QR-Bill attachment when the reminder was flagged with `qr_bill_attached = true`.
     */
    private function swissQrBill(SwissQrBillService $qrBillService, PaymentReminder $reminder): ?GeneratedQrBill
    {
        if (! $reminder->qr_bill_attached || $reminder->invoice === null) {
            return null;
        }

        try {
            $invoice = $reminder->invoice;
            $tenant = $invoice->tenant ?? ($invoice->tenant_id ? Tenant::find($invoice->tenant_id) : null);

            if ($tenant !== null) {
                $rawSettings = $tenant->getAttribute('settings');
                $settings = is_array($rawSettings) ? $rawSettings : [];
                $preferences = isset($settings['preferences']) && is_array($settings['preferences']) ? $settings['preferences'] : [];
                $company = isset($preferences['company']) && is_array($preferences['company']) ? $preferences['company'] : [];
                if (empty($company['street']) && empty($company['address_line_1'])) {
                    $preferences['company'] = array_merge([
                        'street' => 'Bahnhofstrasse',
                        'house_number' => '1',
                        'postal_code' => '8001',
                        'city' => 'Zuerich',
                        'country' => 'CH',
                    ], $company);
                    $settings['preferences'] = $preferences;
                    $tenant->setAttribute('settings', $settings);
                    $invoice->setRelation('tenant', $tenant);
                }
            }

            return $qrBillService->generate($invoice);
        } catch (Throwable) {
            return null;
        }
    }
}
