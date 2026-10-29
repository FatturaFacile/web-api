<?php

declare(strict_types=1);

namespace App\Actions\ServiceReports;

use App\Events\ServiceReports\ServiceReportInvoiced;
use App\Models\Invoice;
use App\Models\InvoiceServiceReport;
use App\Models\LookupItem;
use App\Models\ServiceReport;
use App\Services\Sales\DocumentNumberingService;
use App\Services\Sales\DocumentTotalsCalculator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class GenerateInvoiceFromServiceReportAction
{
    public function __construct(
        private readonly DocumentNumberingService $numbering,
        private readonly DocumentTotalsCalculator $totalsCalculator,
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
                'quantity' => (float) $line->quantity,
                'unit_price' => (float) $line->unit_price,
                'tax_rate' => (float) $line->tax_rate,
                'discount_percentage' => (float) $line->discount_percent,
            ])->values()->all();

            $calculatedTotals = $this->totalsCalculator->calculate($lineItems);
            $year = (int) now()->format('Y');
            $numberData = $this->numbering->next('invoice', $year);
            $currency = $report->contact?->currency ?? $report->tenant?->default_currency ?? 'EUR';

            /** @var Invoice $invoice */
            $invoice = Invoice::create([
                'tenant_id' => $report->tenant_id,
                'contact_id' => $report->contact_id,
                'invoice_type' => 'standard',
                'invoice_number' => $numberData['number'],
                'fiscal_year' => $numberData['fiscal_year'],
                'sequential_number' => $numberData['sequential_number'],
                'date' => now()->toDateString(),
                'due_date' => now()->addDays(30)->toDateString(),
                'currency' => $currency,
                'total_net' => $calculatedTotals['total_net'],
                'total_tax' => $calculatedTotals['total_tax'],
                'total_gross' => $calculatedTotals['total_gross'],
                'payment_status' => 'unpaid',
                'notes' => "Generated from Service Report {$report->report_number}",
            ]);

            foreach ($calculatedTotals['items'] as $itemData) {
                $invoice->items()->create($itemData);
            }

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
