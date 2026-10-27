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
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class GenerateConsolidatedInvoiceAction
{
    public function __construct(
        private readonly DocumentNumberingService $numbering,
        private readonly DocumentTotalsCalculator $totalsCalculator,
    ) {}

    /**
     * Merge multiple signed service reports into a single consolidated customer invoice.
     *
     * @param  array<int, string>  $reportIds
     */
    public function execute(array $reportIds): Invoice
    {
        return DB::transaction(function () use ($reportIds): Invoice {
            if (count($reportIds) < 2) {
                throw ValidationException::withMessages([
                    'report_ids' => 'At least two service reports are required to generate a consolidated invoice.',
                ]);
            }

            /** @var Collection<int, ServiceReport> $reports */
            $reports = ServiceReport::lockForUpdate()
                ->with(['lines.item', 'contact'])
                ->whereIn('id', $reportIds)
                ->get();

            if ($reports->count() !== count($reportIds)) {
                throw ValidationException::withMessages([
                    'report_ids' => 'One or more service reports could not be found.',
                ]);
            }

            // Ensure all reports belong to the exact same contact
            $contactIds = $reports->pluck('contact_id')->unique();
            if ($contactIds->count() > 1) {
                throw ValidationException::withMessages([
                    'report_ids' => 'All consolidated service reports must belong to the same customer/contact.',
                ]);
            }

            // Ensure all reports are signed and uninvoiced
            foreach ($reports as $report) {
                if ($report->signed_at === null) {
                    throw ValidationException::withMessages([
                        'report_ids' => "Service report {$report->report_number} is not signed by the customer.",
                    ]);
                }

                if ($report->invoiced_at !== null || $report->invoiceLink()->exists()) {
                    throw ValidationException::withMessages([
                        'report_ids' => "Service report {$report->report_number} has already been invoiced.",
                    ]);
                }
            }

            $lineItems = [];
            foreach ($reports as $report) {
                foreach ($report->lines->where('is_billable', true) as $line) {
                    $lineItems[] = [
                        'product_id' => $line->item_id,
                        'description' => "[{$report->report_number}] {$line->description}",
                        'quantity' => (float) $line->quantity,
                        'unit_price' => (float) $line->unit_price,
                        'tax_rate' => (float) $line->tax_rate,
                        'discount_percentage' => (float) $line->discount_percent,
                    ];
                }
            }

            if (empty($lineItems)) {
                throw ValidationException::withMessages([
                    'report_ids' => 'None of the selected service reports contain billable lines.',
                ]);
            }

            $firstReport = $reports->first();
            $calculatedTotals = $this->totalsCalculator->calculate($lineItems);
            $year = (int) now()->format('Y');
            $numberData = $this->numbering->next('invoice', $year);

            $reportNumbers = $reports->pluck('report_number')->implode(', ');

            /** @var Invoice $invoice */
            $invoice = Invoice::create([
                'tenant_id' => $firstReport->tenant_id,
                'contact_id' => $firstReport->contact_id,
                'invoice_type' => 'standard',
                'invoice_number' => $numberData['number'],
                'fiscal_year' => $numberData['fiscal_year'],
                'sequential_number' => $numberData['sequential_number'],
                'date' => now()->toDateString(),
                'due_date' => now()->addDays(30)->toDateString(),
                'currency' => 'EUR',
                'total_net' => $calculatedTotals['total_net'],
                'total_tax' => $calculatedTotals['total_tax'],
                'total_gross' => $calculatedTotals['total_gross'],
                'payment_status' => 'unpaid',
                'notes' => "Consolidated Invoice for Service Reports: {$reportNumbers}",
            ]);

            foreach ($calculatedTotals['items'] as $itemData) {
                $invoice->items()->create($itemData);
            }

            $invoicedStatus = LookupItem::whereHas('category', fn ($q) => $q->where('code', 'service_report_status'))
                ->where('code', 'invoiced')
                ->first();

            foreach ($reports as $report) {
                InvoiceServiceReport::create([
                    'tenant_id' => $report->tenant_id,
                    'invoice_id' => $invoice->id,
                    'service_report_id' => $report->id,
                    'is_consolidated' => true,
                    'invoiced_net_amount' => $report->total_net,
                    'created_by' => Auth::id(),
                ]);

                $report->update([
                    'status_id' => $invoicedStatus?->id ?? $report->status_id,
                    'invoiced_at' => now(),
                    'updated_by' => Auth::id(),
                ]);
            }

            event(new ServiceReportInvoiced($invoice, $reports->all(), true));

            return $invoice->load(['items', 'contact']);
        });
    }
}
