<?php

declare(strict_types=1);

namespace Modules\SubscriptionBilling\Services;

use Carbon\Carbon;
use Enterprise\AuditLedger\Contracts\AuditLedgerInterface;
use Enterprise\EventBus\Services\EventDispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\SubscriptionBilling\Contracts\RevenueRecognitionServiceInterface;
use Modules\SubscriptionBilling\DTOs\RecognizeRevenueDTO;
use Modules\SubscriptionBilling\Events\RevenueRecognitionReadyEvent;
use Modules\SubscriptionBilling\Events\RevenueRecognizedEvent;
use Modules\SubscriptionBilling\Models\PerformanceObligation;
use Modules\SubscriptionBilling\Models\RevenueContract;
use Modules\SubscriptionBilling\Models\RevenueRecognitionEntry;
use Modules\SubscriptionBilling\Models\RevenueSchedule;
use Modules\SubscriptionBilling\Models\Subscription;

final readonly class RevenueRecognitionService implements RevenueRecognitionServiceInterface
{
    public function __construct(
        private EventDispatcher $dispatcher,
        private AuditLedgerInterface $auditLedger
    ) {}

    public function createRevenueContract(Subscription $subscription): RevenueContract
    {
        return DB::transaction(function () use ($subscription) {
            $subscription->load(['items', 'plan']);

            $contractNumber = 'REV-CTR-'.date('Ymd').'-'.strtoupper(Str::random(6));
            $totalTransactionPrice = (float) $subscription->items->sum('subtotal_recurring');
            $termMonths = max(1, (int) $subscription->contract_term_months);

            // Annualized or total contract value over full term
            $billingInterval = $subscription->plan?->billing_interval ?? 'monthly';
            $cycleMultiplier = match ($billingInterval) {
                'annual' => 1,
                'quarterly' => ceil($termMonths / 3),
                'semiannual' => ceil($termMonths / 6),
                default => $termMonths,
            };

            $totalContractPrice = round($totalTransactionPrice * $cycleMultiplier, 2);

            $startDate = Carbon::parse($subscription->start_date);
            $endDate = $startDate->copy()->addMonths($termMonths)->subDay();

            $revenueContract = RevenueContract::create([
                'tenant_id' => $subscription->tenant_id,
                'company_id' => $subscription->company_id,
                'contract_number' => $contractNumber,
                'subscription_id' => $subscription->id,
                'total_transaction_price' => $totalContractPrice,
                'currency' => $subscription->currency,
                'inception_date' => $startDate->toDateString(),
                'termination_date' => $endDate->toDateString(),
                'status' => 'active',
            ]);

            // Create Performance Obligations (POB) for each subscription item
            foreach ($subscription->items as $index => $item) {
                $pobCode = 'POB-'.($index + 1).'-'.strtoupper(substr($item->item_type, 0, 4));
                $ssp = (float) $item->subtotal_recurring * $cycleMultiplier;

                PerformanceObligation::create([
                    'tenant_id' => $subscription->tenant_id,
                    'revenue_contract_id' => $revenueContract->id,
                    'pob_code' => $pobCode,
                    'description' => $item->description,
                    'standalone_selling_price' => $ssp,
                    'allocated_transaction_price' => $ssp,
                    'recognition_method' => $item->item_type === 'setup_fee' ? 'point_in_time' : 'straight_line_over_time',
                    'start_date' => $startDate->toDateString(),
                    'end_date' => $endDate->toDateString(),
                    'recognized_revenue_to_date' => 0.00,
                    'deferred_revenue_balance' => $ssp,
                    'gl_revenue_account_id' => null,
                    'gl_deferred_account_id' => null,
                    'status' => 'in_progress',
                ]);
            }

            $this->allocateTransactionPrice($revenueContract);
            $this->generateRevenueSchedules($revenueContract);

            $this->auditLedger->record(
                'REVENUE_CONTRACT_CREATED',
                $revenueContract,
                null,
                ['contract_number' => $contractNumber, 'total_price' => $totalContractPrice],
                'MODULE_19_SUB'
            );

            return $revenueContract;
        });
    }

    public function allocateTransactionPrice(RevenueContract $contract): void
    {
        $pobs = $contract->performanceObligations;
        $totalSsp = (float) $pobs->sum('standalone_selling_price');
        $totalTransactionPrice = (float) $contract->total_transaction_price;

        if ($totalSsp <= 0.0) {
            return;
        }

        $allocatedSum = 0.00;
        $count = $pobs->count();

        foreach ($pobs as $i => $pob) {
            if ($i === $count - 1) {
                // Last item absorbs rounding difference
                $allocatedPrice = round($totalTransactionPrice - $allocatedSum, 2);
            } else {
                $proportion = (float) $pob->standalone_selling_price / $totalSsp;
                $allocatedPrice = round($totalTransactionPrice * $proportion, 2);
                $allocatedSum += $allocatedPrice;
            }

            $pob->update([
                'allocated_transaction_price' => $allocatedPrice,
                'deferred_revenue_balance' => $allocatedPrice,
            ]);
        }
    }

    public function generateRevenueSchedules(RevenueContract $contract): array
    {
        $schedules = [];
        $contract->load('performanceObligations');

        foreach ($contract->performanceObligations as $pob) {
            $startDate = Carbon::parse($pob->start_date);
            $endDate = Carbon::parse($pob->end_date);
            $allocatedPrice = (float) $pob->allocated_transaction_price;

            if ($pob->recognition_method === 'point_in_time') {
                $schedules[] = RevenueSchedule::create([
                    'tenant_id' => $contract->tenant_id,
                    'performance_obligation_id' => $pob->id,
                    'period_date' => $startDate->toDateString(),
                    'scheduled_amount' => $allocatedPrice,
                    'recognized_amount' => 0.00,
                    'is_recognized' => false,
                ]);

                continue;
            }

            // Straight-line over time (monthly periods)
            $months = max(1, $startDate->diffInMonths($endDate->copy()->addDay()));
            $monthlyAmount = round($allocatedPrice / $months, 2);
            $accumulated = 0.00;

            for ($m = 0; $m < $months; $m++) {
                $periodDate = $startDate->copy()->addMonths($m)->endOfMonth();
                $sliceAmount = ($m === $months - 1)
                    ? round($allocatedPrice - $accumulated, 2)
                    : $monthlyAmount;

                $accumulated += $sliceAmount;

                $schedules[] = RevenueSchedule::create([
                    'tenant_id' => $contract->tenant_id,
                    'performance_obligation_id' => $pob->id,
                    'period_date' => $periodDate->toDateString(),
                    'scheduled_amount' => $sliceAmount,
                    'recognized_amount' => 0.00,
                    'is_recognized' => false,
                ]);
            }
        }

        return $schedules;
    }

    public function recognizeRevenue(RecognizeRevenueDTO $dto): array
    {
        return DB::transaction(function () use ($dto) {
            $periodDate = $dto->periodDate ? Carbon::parse($dto->periodDate) : now();

            $pendingSchedules = RevenueSchedule::with('performanceObligation.revenueContract')
                ->where('tenant_id', $dto->tenantId)
                ->where('is_recognized', false)
                ->where('period_date', '<=', $periodDate->toDateString())
                ->lockForUpdate()
                ->get();

            $recognizedEntries = [];
            $totalRecognized = 0.00;

            foreach ($pendingSchedules as $schedule) {
                $pob = $schedule->performanceObligation;
                $amount = (float) $schedule->scheduled_amount;

                $auditHash = hash('sha256', $schedule->id.':'.$amount.':'.now()->toIso8601String());

                $entry = RevenueRecognitionEntry::create([
                    'tenant_id' => $dto->tenantId,
                    'company_id' => $dto->companyId,
                    'performance_obligation_id' => $pob->id,
                    'recognition_date' => $periodDate->toDateString(),
                    'amount' => $amount,
                    'currency' => $pob->revenueContract?->currency ?? 'EUR',
                    'audit_hash' => $auditHash,
                ]);

                // Update POB balances
                $newRecognized = round((float) $pob->recognized_revenue_to_date + $amount, 2);
                $newDeferred = max(0.00, round((float) $pob->deferred_revenue_balance - $amount, 2));

                $pob->update([
                    'recognized_revenue_to_date' => $newRecognized,
                    'deferred_revenue_balance' => $newDeferred,
                    'status' => $newDeferred <= 0.001 ? 'satisfied' : 'in_progress',
                ]);

                $schedule->update([
                    'is_recognized' => true,
                    'recognized_at' => now(),
                    'recognized_amount' => $amount,
                ]);

                $totalRecognized += $amount;
                $recognizedEntries[] = $entry;

                // Dispatch domain & accounting events
                $this->dispatcher->dispatch(new RevenueRecognizedEvent(
                    recognitionEntryId: $entry->id,
                    performanceObligationId: $pob->id,
                    amount: $amount,
                    recognitionDate: $periodDate->toDateString(),
                    currency: $entry->currency,
                    tenantId: $dto->tenantId,
                    companyId: (string) ($dto->companyId ?? $dto->tenantId)
                ));

                // Journal entry integration: Dr Deferred Revenue / Cr Subscription Revenue
                $this->dispatcher->dispatch(new RevenueRecognitionReadyEvent(
                    recognitionEntryId: $entry->id,
                    subscriptionId: (string) ($pob->revenueContract?->subscription_id ?? ''),
                    amount: $amount,
                    currency: $entry->currency,
                    debitAccount: '2400_DEFERRED_REVENUE',
                    creditAccount: '4000_SUBSCRIPTION_REVENUE',
                    recognitionDate: $periodDate->toDateString(),
                    tenantId: $dto->tenantId,
                    companyId: (string) ($dto->companyId ?? $dto->tenantId)
                ));
            }

            if (! empty($recognizedEntries)) {
                $this->auditLedger->record(
                    'REVENUE_RECOGNITION_BATCH_COMPLETED',
                    $recognizedEntries[0],
                    null,
                    ['count' => count($recognizedEntries), 'total_amount' => $totalRecognized],
                    'MODULE_19_SUB'
                );
            }

            return [
                'entries_count' => count($recognizedEntries),
                'total_amount' => $totalRecognized,
                'entries' => $recognizedEntries,
            ];
        });
    }
}
