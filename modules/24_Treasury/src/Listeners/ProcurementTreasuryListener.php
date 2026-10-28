<?php

declare(strict_types=1);

namespace Modules\Treasury\Listeners;

use Enterprise\AuditLedger\Contracts\AuditLedgerInterface;
use Illuminate\Support\Str;
use Modules\Procurement\Events\PurchaseLiabilityReadyEvent;
use Modules\Treasury\Models\BankAccount;
use Modules\Treasury\Models\PaymentBatch;
use Modules\Treasury\Models\PaymentLine;

final readonly class ProcurementTreasuryListener
{
    public function __construct(
        private AuditLedgerInterface $auditLedger
    ) {}

    public function handlePurchaseLiabilityReady(PurchaseLiabilityReadyEvent $event): void
    {
        $bankAccount = BankAccount::where('tenant_id', $event->tenantId)
            ->where('is_active', true)
            ->first();

        if (! $bankAccount) {
            return;
        }

        $dueDate = now()->addDays(30)->toDateString();

        $batch = PaymentBatch::firstOrCreate(
            [
                'tenant_id' => $event->tenantId,
                'bank_account_id' => $bankAccount->id,
                'execution_date' => $dueDate,
                'status' => 'draft',
            ],
            [
                'id' => (string) Str::uuid7(),
                'company_id' => $event->companyId,
                'batch_reference' => 'PAY-PROC-'.date('Ymd').'-'.strtoupper(Str::random(4)),
                'payment_method' => 'sepa_credit_transfer',
                'currency' => $event->currency,
                'total_amount' => 0.00,
                'item_count' => 0,
                'created_by' => (string) Str::uuid7(),
            ]
        );

        $grossAmount = $event->grossAmount;
        $invoiceRef = $event->vendorInvoiceNumber;

        $line = PaymentLine::create([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $event->tenantId,
            'company_id' => $event->companyId,
            'payment_batch_id' => $batch->id,
            'line_number' => $batch->lines()->count() + 1,
            'recipient_type' => 'supplier',
            'recipient_id' => $event->supplierId,
            'recipient_name' => "Vendor {$event->supplierId}",
            'recipient_iban' => 'IT02L1234512345000000123456',
            'amount' => $grossAmount,
            'currency' => $event->currency,
            'instruction_id' => 'INSTR-PO-'.strtoupper(Str::random(6)),
            'end_to_end_id' => 'E2E-PO-'.strtoupper(Str::random(8)),
            'remittance_reference' => "PO Payment for PO {$event->purchaseOrderId} (Inv: {$invoiceRef})",
            'invoice_id' => Str::isUuid($event->matchLogId) ? $event->matchLogId : null,
            'status' => 'pending',
            'execution_date' => $dueDate,
        ]);

        $batch->update([
            'total_amount' => (float) $batch->total_amount + $grossAmount,
            'item_count' => $batch->item_count + 1,
        ]);

        $this->auditLedger->record(
            action: 'procurement_liability_scheduled',
            model: $line,
            payloadBefore: null,
            payloadAfter: $line->toArray(),
            moduleCode: 'MODULE_24_TREASURY'
        );
    }
}
