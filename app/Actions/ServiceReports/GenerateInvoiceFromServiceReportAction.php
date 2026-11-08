<?php

declare(strict_types=1);

namespace App\Actions\ServiceReports;

use App\Events\ServiceReports\ServiceReportInvoiced;
use App\Models\Invoice;
use App\Models\InvoiceServiceReport;
use App\Models\LookupItem;
use App\Models\ServiceReport;
use App\Services\Invoicing\InvoiceService;
use App\Services\Sales\DocumentTotalsCalculator;
use App\Services\Tenancy\TenantClock;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class GenerateInvoiceFromServiceReportAction
{
    public function __construct(
        private readonly InvoiceService $invoices,
        private readonly TenantClock $clock,
    ) {}

    /**
     * Generate a direct 1-to-1 invoice from a signed service report.
     */
    public function execute(ServiceReport $report): Invoice
    {
        return DB::transaction(function () use ($report): Invoice {
            $report = ServiceReport::lockForUpdate()->with(['lines.item', 'contact', 'status', 'tenant'])->findOrFail($report->id);

            if ($report->status?->code === 'cancelled') {
                throw ValidationException::withMessages([
                    'service_report' => 'Cancelled service reports cannot be invoiced.',
                ]);
            }

            if ($report->signed_at === null) {
                throw ValidationException::withMessages([
                    'service_report' => 'The service report must be signed by the customer before generating an invoice.',
                ]);
            }

            if ($report->invoiced_at !== null || $report->invoiceLink()->exists()) {
                throw ValidationException::withMessages([
                    'service_report' => 'This service report has already been invoiced.',
                ]);
            }

            $billableLines = $report->lines->where('is_billable', true);

            if ($billableLines->isEmpty()) {
                throw ValidationException::withMessages([
                    'service_report' => 'The service report has no billable lines.',
                ]);
            }

            $lineItems = $billableLines->map(fn ($line): array => [
                'product_id' => $line->item_id,
                'description' => $line->description,
                'quantity' => DocumentTotalsCalculator::decimal($line->quantity),
                'unit_price' => DocumentTotalsCalculator::decimal($line->unit_price),
                'tax_rate' => DocumentTotalsCalculator::decimal($line->tax_rate),
                'discount_percentage' => DocumentTotalsCalculator::decimal($line->discount_percent),
            ])->values()->all();

            $currency = $report->contact?->currency ?? $report->tenant?->default_currency ?? 'EUR';
            $today = $this->clock->today($report->tenant);

            $invoice = $this->invoices->issue([
                'tenant_id' => (string) $report->tenant_id,
                'contact_id' => (string) $report->contact_id,
                'invoice_type' => 'standard',
                'date' => $today->toDateString(),
                'due_date' => $today->addDays(30)->toDateString(),
                'currency' => $currency,
                'notes' => "Generated from Service Report {$report->report_number}",
            ], $lineItems);

            InvoiceServiceReport::create([
                'tenant_id' => $report->tenant_id,
                'invoice_id' => $invoice->id,
                'service_report_id' => $report->id,
                'is_consolidated' => false,
                'invoiced_net_amount' => $report->total_net,
                'created_by' => Auth::id(),
            ]);

            $invoicedStatus = LookupItem::whereHas('category', fn ($q) => $q->where('code', 'service_report_status'))
                ->where('code', 'invoiced')
                ->first();

            $report->update([
                'status_id' => $invoicedStatus?->id ?? $report->status_id,
                'invoiced_at' => now(),
                'updated_by' => Auth::id(),
            ]);

            event(new ServiceReportInvoiced($invoice, [$report], false));

            return $invoice->load(['items', 'contact']);
        });
    }
}
