<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Listeners;

use Illuminate\Support\Str;
use Modules\GeneralLedger\Contracts\DoubleEntryPostingServiceInterface;
use Modules\GeneralLedger\Models\ChartOfAccount;
use Modules\GeneralLedger\Models\FiscalPeriod;
use Modules\Procurement\Events\GoodsReceivedEvent;
use Modules\Procurement\Events\PurchaseLiabilityReadyEvent;
use Modules\Procurement\Events\PurchaseOrderCancelledEvent;
use Modules\Procurement\Events\PurchaseOrderCommittedEvent;

final class ProcurementAccountingListener
{
    public function __construct(
        private readonly DoubleEntryPostingServiceInterface $postingService
    ) {}

    public function handlePurchaseOrderCommitted(PurchaseOrderCommittedEvent $event): void
    {
        $period = $this->resolveFiscalPeriod($event->tenantId);
        if (! $period) {
            return;
        }

        $encumbranceExpAcc = $this->resolveAccount($event->tenantId, '6990', 'Encumbrance Expense', 'expense', 'debit');
        $encumbranceResAcc = $this->resolveAccount($event->tenantId, '3990', 'Reserve for Encumbrances', 'equity', 'credit');

        $this->postingService->createAndPost([
            'tenant_id' => $event->tenantId,
            'company_id' => $event->companyId,
            'fiscal_period_id' => $period->id,
            'entry_number' => 'ENC-'.$event->purchaseOrderNumber,
            'source_document_type' => 'purchase_order',
            'source_document_id' => $event->purchaseOrderId,
            'journal_type' => 'standard',
            'description' => "PO Encumbrance Commitment for {$event->purchaseOrderNumber}",
            'lines' => [
                [
                    'account_id' => $encumbranceExpAcc->id,
                    'debit_amount' => $event->committedAmount,
                    'credit_amount' => 0.0,
                    'description' => 'PO Encumbrance',
                ],
                [
                    'account_id' => $encumbranceResAcc->id,
                    'debit_amount' => 0.0,
                    'credit_amount' => $event->committedAmount,
                    'description' => 'PO Encumbrance Reserve',
                ],
            ],
        ]);
    }

    public function handlePurchaseOrderCancelled(PurchaseOrderCancelledEvent $event): void
    {
        $period = $this->resolveFiscalPeriod($event->tenantId);
        if (! $period) {
            return;
        }

        $encumbranceExpAcc = $this->resolveAccount($event->tenantId, '6990', 'Encumbrance Expense', 'expense', 'debit');
        $encumbranceResAcc = $this->resolveAccount($event->tenantId, '3990', 'Reserve for Encumbrances', 'equity', 'credit');

        $this->postingService->createAndPost([
            'tenant_id' => $event->tenantId,
            'company_id' => $event->companyId,
            'fiscal_period_id' => $period->id,
            'entry_number' => 'REV-ENC-'.$event->purchaseOrderId,
            'source_document_type' => 'purchase_order',
            'source_document_id' => $event->purchaseOrderId,
            'journal_type' => 'reversing',
            'description' => "PO Encumbrance Reversal: {$event->cancellationReason}",
            'lines' => [
                [
                    'account_id' => $encumbranceResAcc->id,
                    'debit_amount' => 100.0, // reversal placeholder if no amount
                    'credit_amount' => 0.0,
                    'description' => 'Reverse Encumbrance Reserve',
                ],
                [
                    'account_id' => $encumbranceExpAcc->id,
                    'debit_amount' => 0.0,
                    'credit_amount' => 100.0,
                    'description' => 'Reverse Encumbrance Expense',
                ],
            ],
        ]);
    }

    public function handleGoodsReceived(GoodsReceivedEvent $event): void
    {
        $period = $this->resolveFiscalPeriod($event->tenantId);
        if (! $period) {
            return;
        }

        $invAcc = $this->resolveAccount($event->tenantId, '1200', 'Merchandise Inventory Asset', 'asset', 'debit');
        $grIrAcc = $this->resolveAccount($event->tenantId, '2010', 'GR/IR Clearing Account', 'liability', 'credit');

        // Estimate receipt cost (e.g. standard 100.00 per line if not priced)
        $totalEst = 0.0;
        foreach ($event->receivedLines as $l) {
            $qty = (float) ($l['quantity_received'] ?? 1.0);
            $totalEst += $qty * 50.0;
        }
        if ($totalEst <= 0) {
            $totalEst = 100.0;
        }

        $this->postingService->createAndPost([
            'tenant_id' => $event->tenantId,
            'company_id' => $event->companyId,
            'fiscal_period_id' => $period->id,
            'entry_number' => 'GR-'.$event->receiptNumber,
            'source_document_type' => 'goods_receipt',
            'source_document_id' => $event->receiptId,
            'journal_type' => 'standard',
            'description' => "GR/IR Accrual for Receipt {$event->receiptNumber}",
            'lines' => [
                [
                    'account_id' => $invAcc->id,
                    'debit_amount' => $totalEst,
                    'credit_amount' => 0.0,
                    'description' => "Inventory Receipt: {$event->supplierDeliveryNoteRef}",
                ],
                [
                    'account_id' => $grIrAcc->id,
                    'debit_amount' => 0.0,
                    'credit_amount' => $totalEst,
                    'description' => 'GR/IR Clearing Liability',
                ],
            ],
        ]);
    }

    public function handlePurchaseLiabilityReady(PurchaseLiabilityReadyEvent $event): void
    {
        $period = $this->resolveFiscalPeriod($event->tenantId);
        if (! $period) {
            return;
        }

        $grIrAcc = $this->resolveAccount($event->tenantId, '2010', 'GR/IR Clearing Account', 'liability', 'credit');
        $vatAcc = $this->resolveAccount($event->tenantId, '1400', 'IVA c/Acquisti (Purchase VAT)', 'asset', 'debit');
        $apAcc = $this->resolveAccount($event->tenantId, '2000', 'Accounts Payable (Debiti v/Fornitori)', 'liability', 'credit');
        $apAcc->update(['is_reconciliation_account' => true, 'reconciliation_type' => 'supplier']);

        $lines = [
            [
                'account_id' => $grIrAcc->id,
                'debit_amount' => (float) $event->netAmount,
                'credit_amount' => 0.0,
                'description' => "Clear GR/IR: Invoice {$event->vendorInvoiceNumber}",
            ],
        ];

        if ((float) $event->taxAmount > 0) {
            $lines[] = [
                'account_id' => $vatAcc->id,
                'debit_amount' => (float) $event->taxAmount,
                'credit_amount' => 0.0,
                'description' => "Input VAT for Invoice {$event->vendorInvoiceNumber}",
            ];
        }

        $lines[] = [
            'account_id' => $apAcc->id,
            'debit_amount' => 0.0,
            'credit_amount' => (float) $event->grossAmount,
            'partner_type' => 'supplier',
            'partner_id' => $event->supplierId,
            'description' => "Supplier Payable: Invoice {$event->vendorInvoiceNumber}",
        ];

        $this->postingService->createAndPost([
            'tenant_id' => $event->tenantId,
            'company_id' => $event->companyId,
            'fiscal_period_id' => $period->id,
            'entry_number' => 'VEND-INV-'.strtoupper(Str::random(6)),
            'source_document_type' => 'vendor_invoice',
            'source_document_id' => $event->matchLogId,
            'journal_type' => 'standard',
            'description' => "Vendor Liability for Invoice {$event->vendorInvoiceNumber}",
            'lines' => $lines,
        ]);
    }

    private function resolveFiscalPeriod(string $tenantId): ?FiscalPeriod
    {
        return FiscalPeriod::where('tenant_id', $tenantId)
            ->where('status', 'open')
            ->first();
    }

    private function resolveAccount(string $tenantId, string $code, string $name, string $type, string $normalBalance): ChartOfAccount
    {
        return ChartOfAccount::firstOrCreate(
            ['tenant_id' => $tenantId, 'account_code' => $code],
            [
                'id' => (string) Str::uuid7(),
                'name' => $name,
                'account_class' => $type,
                'account_type' => $type,
                'normal_balance' => $normalBalance,
                'currency' => 'EUR',
                'is_active' => true,
            ]
        );
    }
}
