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
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class GenerateConsolidatedInvoiceAction
{
    public function __construct(
        private readonly InvoiceService $invoices,
        private readonly TenantClock $clock,
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
                    'report_ids' => __('service_reports_errors.consolidated.min_two'),
                ]);
            }

            sort($reportIds);

            /** @var Collection<int, ServiceReport> $reports */
            $reports = ServiceReport::lockForUpdate()
                ->with(['lines.item', 'contact', 'status', 'tenant'])
                ->whereIn('id', $reportIds)
                ->get();

            if ($reports->count() !== count($reportIds)) {
                throw ValidationException::withMessages([
                    'report_ids' => __('service_reports_errors.consolidated.not_found'),
                ]);
            }

            // Ensure all reports belong to the exact same contact
            $contactIds = $reports->pluck('contact_id')->unique();
            if ($contactIds->count() > 1) {
                throw ValidationException::withMessages([
                    'report_ids' => __('service_reports_errors.consolidated.same_customer'),
                ]);
            }

            // Ensure all reports are signed, uninvoiced, and not cancelled
            foreach ($reports as $report) {
                if ($report->status?->code === 'cancelled') {
                    throw ValidationException::withMessages([
                        'report_ids' => __('service_reports_errors.consolidated.cancelled', ['number' => $report->report_number]),
                    ]);
                }

                if ($report->signed_at === null) {
                    throw ValidationException::withMessages([
                        'report_ids' => __('service_reports_errors.consolidated.not_signed', ['number' => $report->report_number]),
                    ]);
                }

                if ($report->invoiced_at !== null || $report->invoiceLink()->exists()) {
                    throw ValidationException::withMessages([
                        'report_ids' => __('service_reports_errors.consolidated.already_invoiced', ['number' => $report->report_number]),
                    ]);
                }
            }

            $lineItems = [];
            foreach ($reports as $report) {
                foreach ($report->lines->where('is_billable', true) as $line) {
                    $lineItems[] = [
                        'product_id' => $line->item_id,
                        'description' => "[{$report->report_number}] {$line->description}",
                        'quantity' => DocumentTotalsCalculator::decimal($line->quantity),
                        'unit_price' => DocumentTotalsCalculator::decimal($line->unit_price),
                        'tax_rate' => DocumentTotalsCalculator::decimal($line->tax_rate),
                        'discount_percentage' => DocumentTotalsCalculator::decimal($line->discount_percent),
                    ];
                }
            }

            if (empty($lineItems)) {
                throw ValidationException::withMessages([
                    'report_ids' => __('service_reports_errors.consolidated.no_billable_lines'),
                ]);
            }

            $firstReport = $reports->first();
            $currency = $firstReport->contact?->currency ?? $firstReport->tenant?->default_currency ?? 'EUR';
            $today = $this->clock->today($firstReport->tenant);
            $reportNumbers = $reports->pluck('report_number')->implode(', ');

            $invoice = $this->invoices->issue([
                'tenant_id' => (string) $firstReport->tenant_id,
                'contact_id' => (string) $firstReport->contact_id,
                'invoice_type' => 'standard',
                'date' => $today->toDateString(),
                'due_date' => $today->addDays(30)->toDateString(),
                'currency' => $currency,
                'notes' => "Consolidated Invoice for Service Reports: {$reportNumbers}",
            ], $lineItems);

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
