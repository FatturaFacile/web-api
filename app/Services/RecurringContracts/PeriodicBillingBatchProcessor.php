<?php

declare(strict_types=1);

namespace App\Services\RecurringContracts;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\RecurringContracts\RecurringBillingRun;
use App\Models\RecurringContracts\RecurringBillingRunItem;
use App\Models\RecurringContracts\RecurringContract;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class PeriodicBillingBatchProcessor
{
    public function __construct(
        protected ProrationCalculatorService $prorationCalculator
    ) {}

    /**
     * Preview contracts due for billing up to a cutoff date without committing changes.
     *
     * @return array<int, array{
     *   contract_id: string,
     *   contract_number: string,
     *   customer_name: string,
     *   currency: string,
     *   period_start: string,
     *   period_end: string,
     *   next_billing_date: string,
     *   subtotal: string,
     *   tax: string,
     *   total: string,
     *   idempotency_key: string,
     *   is_already_billed: bool,
     * }>
     */
    public function previewDueContracts(CarbonImmutable $cutoffDate, ?string $tenantId = null): array
    {
        $contracts = $this->queryDueContracts($cutoffDate, $tenantId);
        $preview = [];

        foreach ($contracts as $contract) {
            $billingPeriod = $this->resolveBillingPeriod($contract);
            $idempotencyKey = $this->generateIdempotencyKey(
                $contract->id,
                $billingPeriod['period_start'],
                $billingPeriod['period_end']
            );

            $alreadyBilled = RecurringBillingRunItem::where('tenant_id', $contract->tenant_id)
                ->where('idempotency_key', $idempotencyKey)
                ->where('status', 'success')
                ->exists();

            $amounts = $this->calculateContractCycleAmounts($contract, $billingPeriod);

            $preview[] = [
                'contract_id' => $contract->id,
                'contract_number' => $contract->contract_number,
                'customer_name' => $contract->customer ? $contract->customer->company_name : 'N/A',
                'currency' => $contract->currency,
                'period_start' => $billingPeriod['period_start']->toDateString(),
                'period_end' => $billingPeriod['period_end']->toDateString(),
                'next_billing_date' => CarbonImmutable::parse($contract->next_billing_date)->toDateString(),
                'subtotal' => $amounts['subtotal'],
                'tax' => $amounts['tax'],
                'total' => $amounts['total'],
                'idempotency_key' => $idempotencyKey,
                'is_already_billed' => $alreadyBilled,
            ];
        }

        return $preview;
    }

    /**
     * Execute billing batch run for due contracts.
     *
     * @param  array<string>|null  $contractIds  Specific contract UUIDs to process, or null for all due
     */
    public function executeBatchRun(
        CarbonImmutable $cutoffDate,
        string $runType = 'scheduled',
        ?string $tenantId = null,
        ?array $contractIds = null
    ): RecurringBillingRun {
        $contracts = $this->queryDueContracts($cutoffDate, $tenantId);
        if ($contractIds !== null) {
            $contracts = $contracts->whereIn('id', $contractIds);
        }

        $firstContract = $contracts->first();
        $effectiveTenantId = $tenantId ?? ($firstContract ? $firstContract->tenant_id : (string) Str::uuid());

        $run = RecurringBillingRun::create([
            'tenant_id' => $effectiveTenantId,
            'run_type' => $runType,
            'target_date' => $cutoffDate->toDateString(),
            'status' => 'running',
            'contracts_processed' => 0,
            'invoices_generated' => 0,
            'total_amount' => '0.0000',
            'started_at' => now(),
        ]);

        $processedCount = 0;
        $invoicesCount = 0;
        $totalBatchAmount = '0.0000';
        $hasFailures = false;

        foreach ($contracts as $contract) {
            $processedCount++;
            $billingPeriod = $this->resolveBillingPeriod($contract);
            $idempotencyKey = $this->generateIdempotencyKey(
                $contract->id,
                $billingPeriod['period_start'],
                $billingPeriod['period_end']
            );

            // Strict Idempotency check:
            $existingItem = RecurringBillingRunItem::where('tenant_id', $contract->tenant_id)
                ->where('idempotency_key', $idempotencyKey)
                ->where('status', 'success')
                ->first();

            if ($existingItem) {
                // Already successfully billed for this period, skip creating invoice
                RecurringBillingRunItem::create([
                    'tenant_id' => $contract->tenant_id,
                    'run_id' => $run->id,
                    'recurring_contract_id' => $contract->id,
                    'billing_period_start' => $billingPeriod['period_start']->toDateString(),
                    'billing_period_end' => $billingPeriod['period_end']->toDateString(),
                    'idempotency_key' => $idempotencyKey.':skipped:'.Str::random(6),
                    'invoice_id' => $existingItem->invoice_id,
                    'status' => 'skipped',
                    'error_message' => 'Billing period already processed in run '.$existingItem->run_id,
                ]);

                // Still advance dates if not already advanced
                $this->advanceContractDates($contract, $billingPeriod);

                continue;
            }

            try {
                $invoice = DB::transaction(function () use ($contract, $billingPeriod, $idempotencyKey, $run, &$totalBatchAmount) {
                    $invoice = $this->createInvoiceForContract($contract, $billingPeriod);

                    RecurringBillingRunItem::create([
                        'tenant_id' => $contract->tenant_id,
                        'run_id' => $run->id,
                        'recurring_contract_id' => $contract->id,
                        'billing_period_start' => $billingPeriod['period_start']->toDateString(),
                        'billing_period_end' => $billingPeriod['period_end']->toDateString(),
                        'idempotency_key' => $idempotencyKey,
                        'invoice_id' => $invoice->id,
                        'status' => 'success',
                    ]);

                    $totalBatchAmount = bcadd($totalBatchAmount, (string) $invoice->total_gross, 4);
                    $this->advanceContractDates($contract, $billingPeriod);

                    return $invoice;
                });

                $invoicesCount++;
            } catch (Throwable $e) {
                $hasFailures = true;
                RecurringBillingRunItem::create([
                    'tenant_id' => $contract->tenant_id,
                    'run_id' => $run->id,
                    'recurring_contract_id' => $contract->id,
                    'billing_period_start' => $billingPeriod['period_start']->toDateString(),
                    'billing_period_end' => $billingPeriod['period_end']->toDateString(),
                    'idempotency_key' => $idempotencyKey.':failed:'.Str::random(6),
                    'invoice_id' => null,
                    'status' => 'failed',
                    'error_message' => $e->getMessage(),
                ]);
            }
        }

        $finalStatus = 'completed';
        if ($hasFailures) {
            $finalStatus = $invoicesCount > 0 ? 'partial_failure' : 'failed';
        }

        $run->update([
            'status' => $finalStatus,
            'contracts_processed' => $processedCount,
            'invoices_generated' => $invoicesCount,
            'total_amount' => $totalBatchAmount,
            'finished_at' => now(),
        ]);

        return $run->refresh();
    }

    /**
     * Bill a single contract immediately.
     */
    public function billContractNow(RecurringContract $contract): Invoice
    {
        $billingPeriod = $this->resolveBillingPeriod($contract);
        $idempotencyKey = $this->generateIdempotencyKey(
            $contract->id,
            $billingPeriod['period_start'],
            $billingPeriod['period_end']
        );

        $run = RecurringBillingRun::create([
            'tenant_id' => $contract->tenant_id,
            'run_type' => 'manual',
            'target_date' => now()->toDateString(),
            'status' => 'running',
            'contracts_processed' => 1,
            'invoices_generated' => 0,
            'total_amount' => '0.0000',
            'started_at' => now(),
        ]);

        return DB::transaction(function () use ($contract, $billingPeriod, $idempotencyKey, $run) {
            $invoice = $this->createInvoiceForContract($contract, $billingPeriod);

            RecurringBillingRunItem::create([
                'tenant_id' => $contract->tenant_id,
                'run_id' => $run->id,
                'recurring_contract_id' => $contract->id,
                'billing_period_start' => $billingPeriod['period_start']->toDateString(),
                'billing_period_end' => $billingPeriod['period_end']->toDateString(),
                'idempotency_key' => $idempotencyKey,
                'invoice_id' => $invoice->id,
                'status' => 'success',
            ]);

            $this->advanceContractDates($contract, $billingPeriod);

            $run->update([
                'status' => 'completed',
                'invoices_generated' => 1,
                'total_amount' => (string) $invoice->total_gross,
                'finished_at' => now(),
            ]);

            return $invoice;
        });
    }

    /**
     * Query all active due contracts up to cutoff date.
     *
     * @return Collection<int, RecurringContract>
     */
    protected function queryDueContracts(CarbonImmutable $cutoffDate, ?string $tenantId = null): Collection
    {
        $query = RecurringContract::with(['customer', 'lines', 'indexationClause'])
            ->where('status', 'active')
            ->where('next_billing_date', '<=', $cutoffDate->toDateString());

        if ($tenantId !== null) {
            $query->where('tenant_id', $tenantId);
        }

        return $query->get();
    }

    /**
     * Resolve billing period start and end for current cycle.
     *
     * @return array{
     *   period_start: CarbonImmutable,
     *   period_end: CarbonImmutable,
     *   next_start: CarbonImmutable,
     * }
     */
    public function resolveBillingPeriod(RecurringContract $contract): array
    {
        $currentBillingDate = CarbonImmutable::parse($contract->next_billing_date);

        if ($contract->billing_timing === 'in_arrears') {
            // Period ended on next_billing_date
            $timingStart = $contract->last_billed_date
                ? CarbonImmutable::parse($contract->last_billed_date)->addDay()
                : CarbonImmutable::parse($contract->start_date);

            $periodData = $this->prorationCalculator->advancePeriod(
                $timingStart,
                $contract->billing_frequency,
                $contract->custom_interval_days
            );
        } else {
            // in_advance: starts on next_billing_date
            $periodData = $this->prorationCalculator->advancePeriod(
                $currentBillingDate,
                $contract->billing_frequency,
                $contract->custom_interval_days
            );
        }

        return $periodData;
    }

    /**
     * Calculate amounts (subtotal, tax, total) for the given billing period with proration.
     */
    public function calculateContractCycleAmounts(RecurringContract $contract, array $billingPeriod): array
    {
        $subtotal = '0.0000';
        $tax = '0.0000';

        $pStart = $billingPeriod['period_start'];
        $pEnd = $billingPeriod['period_end'];

        foreach ($contract->lines as $line) {
            $lineFullSubtotal = $line->calculateLineSubtotal();
            $proratedLineSubtotal = $this->prorationCalculator->calculateProratedAmount(
                $lineFullSubtotal,
                $line->proration_mode,
                $pStart,
                $pEnd,
                $pStart,
                $pEnd
            );

            $lineTax = bcmul($proratedLineSubtotal, bcdiv((string) ($line->tax_rate ?? '0.00'), '100', 4), 4);

            $subtotal = bcadd($subtotal, $proratedLineSubtotal, 4);
            $tax = bcadd($tax, $lineTax, 4);
        }

        $total = bcadd($subtotal, $tax, 4);

        return [
            'subtotal' => $subtotal,
            'tax' => $tax,
            'total' => $total,
        ];
    }

    /**
     * Generate unique idempotency key for this contract & period.
     */
    public function generateIdempotencyKey(string $contractId, CarbonImmutable $start, CarbonImmutable $end): string
    {
        return "contract:{$contractId}:{$start->toDateString()}:{$end->toDateString()}";
    }

    /**
     * Create Invoice and InvoiceItems for contract period.
     */
    protected function createInvoiceForContract(RecurringContract $contract, array $billingPeriod): Invoice
    {
        $amounts = $this->calculateContractCycleAmounts($contract, $billingPeriod);

        $invoiceDate = now()->toDateString();
        $dueDate = $contract->paymentTerm
            ? now()->addDays($contract->paymentTerm->days ?? 30)->toDateString()
            : now()->addDays(30)->toDateString();

        $invoiceNumber = 'REC-'.strtoupper(Str::random(8));

        $status = $contract->invoice_auto_issue ? 'issued' : 'draft';

        $invoice = (new Invoice)->forceFill([
            'tenant_id' => $contract->tenant_id,
            'contact_id' => $contract->customer_id,
            'invoice_type' => 'standard',
            'invoice_number' => $invoiceNumber,
            'date' => $invoiceDate,
            'due_date' => $dueDate,
            'currency' => $contract->currency,
            'exchange_rate' => '1.000000',
            'total_net' => $amounts['subtotal'],
            'total_tax' => $amounts['tax'],
            'total_gross' => $amounts['total'],
            'rounding_amount' => '0.00',
            'total_paid' => '0.00',
            'payment_status' => 'unpaid',
            'status' => $status,
            'notes' => "Recurring contract {$contract->contract_number} ({$contract->title}) - Period: {$billingPeriod['period_start']->toDateString()} to {$billingPeriod['period_end']->toDateString()}",
        ]);

        if ($contract->invoice_auto_issue) {
            $invoice->posted_at = now()->toDateTimeString();
        }

        $invoice->save();

        // Create line items
        foreach ($contract->lines as $line) {
            $lineFullSubtotal = $line->calculateLineSubtotal();
            $proratedSubtotal = $this->prorationCalculator->calculateProratedAmount(
                $lineFullSubtotal,
                $line->proration_mode,
                $billingPeriod['period_start'],
                $billingPeriod['period_end'],
                $billingPeriod['period_start'],
                $billingPeriod['period_end']
            );

            (new InvoiceItem)->forceFill([
                'tenant_id' => $contract->tenant_id,
                'invoice_id' => $invoice->id,
                'product_id' => $line->product_id,
                'description' => $line->description." ({$billingPeriod['period_start']->toDateString()} - {$billingPeriod['period_end']->toDateString()})",
                'quantity' => $line->quantity,
                'unit_price' => $line->unit_price,
                'tax_rate' => $line->tax_rate ?? '0.00',
                'discount_percentage' => $line->discount_percent ?? '0.00',
                'line_total' => $proratedSubtotal,
            ])->save();
        }

        return $invoice;
    }

    /**
     * Advance contract next_billing_date and last_billed_date, or expire if ended.
     */
    protected function advanceContractDates(RecurringContract $contract, array $billingPeriod): void
    {
        $nextDate = $billingPeriod['next_start'];

        // Check if contract has an end_date and does not auto_renew
        if ($contract->end_date !== null) {
            $endDate = CarbonImmutable::parse($contract->end_date);
            if ($nextDate->greaterThan($endDate)) {
                if ($contract->auto_renew && $contract->renewal_period_months) {
                    // Auto-renew: extend end_date
                    $newEndDate = $endDate->addMonthsNoOverflow($contract->renewal_period_months);
                    $contract->update([
                        'end_date' => $newEndDate->toDateString(),
                        'last_billed_date' => $billingPeriod['period_end']->toDateString(),
                        'next_billing_date' => $nextDate->toDateString(),
                    ]);

                    return;
                } else {
                    // Expire contract
                    $contract->update([
                        'status' => 'expired',
                        'last_billed_date' => $billingPeriod['period_end']->toDateString(),
                        'next_billing_date' => $nextDate->toDateString(),
                    ]);

                    return;
                }
            }
        }

        $contract->update([
            'last_billed_date' => $billingPeriod['period_end']->toDateString(),
            'next_billing_date' => $nextDate->toDateString(),
        ]);
    }
}
