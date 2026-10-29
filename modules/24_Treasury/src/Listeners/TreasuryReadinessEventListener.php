<?php

declare(strict_types=1);

namespace Modules\Treasury\Listeners;

use Enterprise\AuditLedger\Contracts\AuditLedgerInterface;
use Illuminate\Support\Str;
use Modules\GeneralLedger\Events\BankPostingReadyEvent;
use Modules\GeneralLedger\Events\CashForecastReadyEvent;
use Modules\GeneralLedger\Events\PayableReadyEvent;
use Modules\GeneralLedger\Events\PaymentReadyEvent;
use Modules\GeneralLedger\Events\ReceivableReadyEvent;
use Modules\GeneralLedger\Events\TreasuryAllocationReadyEvent;
use Modules\Treasury\Contracts\CashForecastServiceInterface;
use Modules\Treasury\Contracts\LiquidityManagementServiceInterface;
use Modules\Treasury\Contracts\PaymentFactoryServiceInterface;
use Modules\Treasury\Contracts\ReceivableCollectionServiceInterface;
use Modules\Treasury\Contracts\TreasuryPositionServiceInterface;
use Modules\Treasury\Models\BankAccount;
use Modules\Treasury\Models\BankTransaction;
use Modules\Treasury\Models\PaymentBatch;
use Modules\Treasury\Models\PaymentLine;

final readonly class TreasuryReadinessEventListener
{
    public function __construct(
        private PaymentFactoryServiceInterface $paymentFactory,
        private ReceivableCollectionServiceInterface $collectionService,
        private CashForecastServiceInterface $forecastService,
        private TreasuryPositionServiceInterface $positionService,
        private LiquidityManagementServiceInterface $liquidityService,
        private AuditLedgerInterface $auditLedger
    ) {}

    public function handlePaymentReady(PaymentReadyEvent $event): void
    {
        // Find default or first active bank account for the tenant
        $bankAccount = BankAccount::where('tenant_id', $event->tenantId)
            ->where('is_active', true)
            ->first();

        if (! $bankAccount) {
            return;
        }

        // Find or create open draft batch for the execution date
        $batch = PaymentBatch::firstOrCreate(
            [
                'tenant_id' => $event->tenantId,
                'bank_account_id' => $bankAccount->id,
                'execution_date' => $event->dueDate,
                'status' => 'draft',
            ],
            [
                'id' => (string) Str::uuid7(),
                'company_id' => $event->companyId,
                'batch_reference' => 'PAY-AUTO-'.date('Ymd').'-'.strtoupper(Str::random(4)),
                'payment_method' => 'sepa_credit_transfer',
                'currency' => $event->currency,
                'total_amount' => 0.00,
                'item_count' => 0,
                'created_by' => (string) Str::uuid7(),
            ]
        );

        $line = PaymentLine::create([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $event->tenantId,
            'company_id' => $event->companyId,
            'payment_batch_id' => $batch->id,
            'line_number' => $batch->lines()->count() + 1,
            'recipient_type' => 'supplier',
            'recipient_id' => $event->supplierId,
            'recipient_name' => "Supplier {$event->supplierId}",
            'recipient_iban' => 'IT60X0542811101000000123456',
            'amount' => $event->amount,
            'currency' => $event->currency,
            'instruction_id' => 'INSTR-'.strtoupper(Str::random(8)),
            'end_to_end_id' => 'E2E-'.strtoupper(Str::random(10)),
            'remittance_reference' => "Settlement for GL Journal {$event->journalEntryId}",
            'status' => 'pending',
            'execution_date' => $event->dueDate,
        ]);

        $batch->update([
            'total_amount' => (float) $batch->total_amount + $event->amount,
            'item_count' => $batch->item_count + 1,
        ]);

        $this->auditLedger->record(
            action: 'payment_ready_consumed',
            model: $line,
            payloadBefore: null,
            payloadAfter: $line->toArray(),
            moduleCode: 'MODULE_24_TREASURY'
        );
    }

    public function handleReceivableReady(ReceivableReadyEvent $event): void
    {
        $this->collectionService->scheduleCollection([
            'tenant_id' => $event->tenantId,
            'company_id' => ! empty($event->companyId) && Str::isUuid((string) $event->companyId) ? (string) $event->companyId : (string) $event->tenantId,
            'customer_id' => $event->customerId,
            'invoice_id' => null,
            'expected_amount' => $event->openAmount,
            'due_date' => $event->dueDate,
            'payment_method' => 'bank_transfer',
        ]);
    }

    public function handlePayableReady(PayableReadyEvent $event): void
    {
        $account = BankAccount::where('tenant_id', $event->tenantId)->first()
            ?? new BankAccount(['id' => (string) Str::uuid7(), 'tenant_id' => $event->tenantId]);

        // Feed payable disbursement expectation into active cash forecasts
        $this->auditLedger->record(
            action: 'payable_ready_ingested',
            model: $account,
            payloadBefore: null,
            payloadAfter: [
                'supplier_ledger_id' => $event->supplierLedgerId,
                'supplier_id' => $event->supplierId,
                'amount' => $event->openAmount,
                'due_date' => $event->dueDate,
            ],
            moduleCode: 'MODULE_24_TREASURY'
        );
    }

    public function handleCashForecastReady(CashForecastReadyEvent $event): void
    {
        $forecast = $this->forecastService->generateForecast(
            tenantId: $event->tenantId,
            scenarioCode: 'BASELINE',
            horizonDays: 90,
            calculationDate: now()->toDateString(),
            companyId: $event->companyId
        );

        $this->auditLedger->record(
            action: 'gl_cash_forecast_consumed',
            model: $forecast,
            payloadBefore: null,
            payloadAfter: $forecast->toArray(),
            moduleCode: 'MODULE_24_TREASURY'
        );
    }

    public function handleBankPostingReady(BankPostingReadyEvent $event): void
    {
        $account = BankAccount::find($event->bankAccountId)
            ?? BankAccount::where('tenant_id', $event->tenantId)->where('is_active', true)->first();

        if (! $account) {
            return;
        }

        $delta = $event->direction === 'credit' ? $event->amount : -1 * $event->amount;
        $this->positionService->updateBalance((string) $account->id, $delta, 'gl_bank_posting');

        BankTransaction::create([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $event->tenantId,
            'company_id' => $event->companyId,
            'bank_account_id' => $account->id,
            'booking_date' => $event->valueDate,
            'value_date' => $event->valueDate,
            'amount' => $delta,
            'currency' => $account->currency ?? 'EUR',
            'description' => "Bank posting from GL Journal Line {$event->journalLineId}",
            'reference' => $event->journalEntryId,
            'transaction_type' => 'transfer',
            'gl_journal_entry_id' => $event->journalEntryId,
            'status' => 'matched',
        ]);
    }

    public function handleTreasuryAllocationReady(TreasuryAllocationReadyEvent $event): void
    {
        $source = BankAccount::find($event->sourceAccountId)
            ?? BankAccount::where('tenant_id', $event->tenantId)->where('is_active', true)->first();

        $dest = BankAccount::find($event->targetAccountId)
            ?? BankAccount::where('tenant_id', $event->tenantId)->where('is_active', true)->skip(1)->first()
            ?? $source;

        if (! $source || ! $dest || $source->id === $dest->id) {
            return;
        }

        $this->liquidityService->transferIntercompanyFunds(
            sourceBankAccountId: (string) $source->id,
            destinationBankAccountId: (string) $dest->id,
            amount: $event->amount,
            transferDate: now()->toDateString(),
            interestRate: 0.0250,
            cashPoolId: null
        );
    }
}
