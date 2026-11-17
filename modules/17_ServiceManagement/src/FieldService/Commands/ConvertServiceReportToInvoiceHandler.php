<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\FieldService\Commands;

use App\Services\Tenancy\TenantClock;
use Enterprise\AuditLedger\Contracts\AuditLedgerInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\GeneralLedger\Services\Tax\Decimal;
use Modules\Invoicing\Contracts\InvoicingServiceInterface;
use Modules\Invoicing\Models\SalesInvoice;
use Modules\ServiceManagement\FieldService\ServiceReportBilling;
use Modules\ServiceManagement\Models\DigitalServiceReport;
use Modules\ServiceManagement\Models\WorkOrder;
use Modules\ServiceManagement\Services\ServiceAmounts;

/**
 * Drafts the sales invoice of a signed service report through the invoicing service (draft: it follows the normal
 * approval, posting and e-invoicing flow). Only billable labor, travel, callout fee and non-covered parts become
 * lines; covered items are left out. The work order moves to `invoiced` and both records keep the invoice link.
 */
final readonly class ConvertServiceReportToInvoiceHandler
{
    public function __construct(
        private ServiceReportBilling $billing,
        private InvoicingServiceInterface $invoicing,
        private AuditLedgerInterface $auditLedger,
        private TenantClock $clock,
    ) {}

    public function __invoke(ConvertServiceReportToInvoiceCommand $command): SalesInvoice
    {
        return DB::transaction(function () use ($command): SalesInvoice {
            $report = DigitalServiceReport::query()->whereKey($command->reportId)->lockForUpdate()->firstOrFail();
            $workOrder = WorkOrder::query()->where('tenant_id', $report->tenant_id)->whereKey($report->work_order_id)->lockForUpdate()->firstOrFail();

            if ($report->invoice_id !== null || $workOrder->is_invoiced) {
                throw ValidationException::withMessages(['report' => __('field_service.errors.report_already_invoiced')]);
            }

            if ($workOrder->status !== 'completed' || blank($workOrder->customer_id)) {
                throw ValidationException::withMessages(['report' => __('field_service.errors.report_not_invoiceable')]);
            }

            $breakdown = $this->billing->breakdown($report);
            $lines = [];

            foreach ($breakdown['lines'] as $line) {
                if ($line['covered'] || ! Decimal::isPositive($line['charged_amount'])) {
                    continue;
                }

                $lines[] = [
                    'description' => $this->describe($line, $report),
                    'quantity' => $line['quantity'],
                    'unit_price' => $line['unit_price'],
                    'product_id' => $line['product_id'],
                    'tax_rate' => ServiceAmounts::text($command->taxRate),
                    'discount_percentage' => '0',
                    'revenue_account_id' => $command->revenueAccountId,
                ];
            }

            if ($lines === []) {
                throw ValidationException::withMessages(['report' => __('field_service.errors.nothing_billable')]);
            }

            $invoice = $this->invoicing->createInvoice([
                'tenant_id' => (string) $workOrder->tenant_id,
                'contact_id' => (string) $workOrder->customer_id,
                'invoice_type' => 'standard',
                'date' => $this->clock->today()->toDateString(),
                'due_date' => $command->dueDate,
                'currency' => $breakdown['currency'],
                'notes' => "Service report {$report->report_number} / work order {$workOrder->work_order_number}",
                'created_by' => $command->actorUserId !== null && Str::isUuid($command->actorUserId) ? $command->actorUserId : null,
            ], $lines);

            $report->update(['invoice_id' => $invoice->id]);
            $workOrder->update(['status' => 'invoiced', 'is_invoiced' => true, 'invoice_id' => $invoice->id]);

            $this->auditLedger->record('SERVICE_REPORT_INVOICED', $report, null, ['invoice_id' => (string) $invoice->id], 'MODULE_17_ESM');

            return $invoice;
        });
    }

    /**
     * @param  array{kind: string, description: string, quantity: string, unit_price: string, list_amount: string, charged_amount: string, covered: bool, product_id: string|null}  $line
     */
    private function describe(array $line, DigitalServiceReport $report): string
    {
        $label = match ($line['kind']) {
            'labor' => 'Labor',
            'travel' => 'Travel time',
            'callout' => 'Callout fee',
            default => $line['description'],
        };

        return "{$label} - {$report->report_number}";
    }
}
