<?php

declare(strict_types=1);

namespace Modules\SalesBoundedContext\Application\Commands;

use Illuminate\Support\Str;
use Modules\SalesBoundedContext\Domain\Enums\SubscriptionLifecycleAction;
use Modules\SalesBoundedContext\Domain\ValueObjects\DecimalMoney;
use Modules\SalesBoundedContext\Events\SubscriptionLifecycleTransitionedEvent;
use Modules\SalesBoundedContext\Models\SbcRecurringSubscription;

final readonly class TransitionSubscriptionLifecycleHandler
{
    public function handle(TransitionSubscriptionLifecycleCommand $command): SbcRecurringSubscription
    {
        $data = $command->payload;
        $currency = strtoupper((string) ($data['currency'] ?? 'CHF'));

        if ($command->subscriptionId === null) {
            $base = DecimalMoney::of((float) ($data['base_recurring_amount'] ?? 0), $currency);
            $activeDays = (int) ($data['pro_rata_active_days'] ?? 30);
            $periodDays = (int) ($data['pro_rata_period_days'] ?? 30);
            $proRata = $base->allocateProRata($activeDays, $periodDays);
            $cycle = (string) ($data['billing_cycle'] ?? 'monthly');
            $mrr = match ($cycle) {
                'annual' => DecimalMoney::of($base->toFloat() / 12.0, $currency),
                'quarterly' => DecimalMoney::of($base->toFloat() / 3.0, $currency),
                default => $base,
            };

            return SbcRecurringSubscription::create([
                'tenant_id' => $command->tenantId,
                'dossier_id' => $data['dossier_id'] ?? null,
                'contract_code' => (string) ($data['contract_code'] ?? 'SUB-'.strtoupper(Str::random(6))),
                'plan_name' => (string) ($data['plan_name'] ?? 'Enterprise SaaS Plan'),
                'billing_cycle' => $cycle,
                'currency' => $currency,
                'base_recurring_amount' => $base->roundHalfUp(),
                'pro_rata_adjustment_amount' => $proRata->roundHalfUp(),
                'indexation_type' => (string) ($data['indexation_type'] ?? 'SWISS_LIK'),
                'last_index_rate_pct' => '0.000',
                'revalued_amount' => $base->roundHalfUp(),
                'mrr_amount' => $mrr->roundHalfUp(),
                'arr_amount' => $mrr->multiply(12)->roundHalfUp(),
                'lifecycle_status' => 'active',
                'next_billing_date' => $data['next_billing_date'] ?? now()->addMonth()->toDateString(),
            ]);
        }

        $sub = SbcRecurringSubscription::query()->where('tenant_id', $command->tenantId)->findOrFail($command->subscriptionId);
        $action = SubscriptionLifecycleAction::from((string) ($data['action'] ?? 'revalue_index'));
        $subCurrency = $sub->currency;
        $currentAmount = DecimalMoney::of((float) $sub->revalued_amount, $subCurrency);

        $newStatus = $sub->lifecycle_status;
        $proRataAmount = DecimalMoney::of((float) $sub->pro_rata_adjustment_amount, $subCurrency);
        $indexRate = (float) $sub->last_index_rate_pct;

        switch ($action) {
            case SubscriptionLifecycleAction::Pause:
                $newStatus = 'paused';
                break;
            case SubscriptionLifecycleAction::Resume:
            case SubscriptionLifecycleAction::Activate:
                $newStatus = 'active';
                break;
            case SubscriptionLifecycleAction::Upgrade:
            case SubscriptionLifecycleAction::Downgrade:
                $newBase = DecimalMoney::of((float) ($data['new_amount'] ?? $currentAmount->toFloat()), $subCurrency);
                $activeDays = (int) ($data['pro_rata_active_days'] ?? 15);
                $periodDays = (int) ($data['pro_rata_period_days'] ?? 30);
                $proRataAmount = $newBase->subtract($currentAmount)->allocateProRata($activeDays, $periodDays);
                $currentAmount = $newBase;
                $newStatus = $action === SubscriptionLifecycleAction::Upgrade ? 'upgraded' : 'downgraded';
                break;
            case SubscriptionLifecycleAction::RevalueIndex:
                $indexRate = (float) ($data['index_rate_pct'] ?? 1.8);
                $currentAmount = $currentAmount->add($currentAmount->percentage($indexRate));
                break;
            case SubscriptionLifecycleAction::Cancel:
                $newStatus = 'cancelled';
                $sub->churn_reason = (string) ($data['churn_reason'] ?? 'Customer requested cancellation');
                break;
        }

        $mrr = match ($newStatus) {
            'cancelled', 'paused' => DecimalMoney::of(0, $subCurrency),
            default => match ($sub->billing_cycle) {
                'annual' => DecimalMoney::of($currentAmount->toFloat() / 12.0, $subCurrency),
                'quarterly' => DecimalMoney::of($currentAmount->toFloat() / 3.0, $subCurrency),
                default => $currentAmount,
            },
        };

        $sub->fill([
            'revalued_amount' => $currentAmount->roundHalfUp(),
            'pro_rata_adjustment_amount' => $proRataAmount->roundHalfUp(),
            'last_index_rate_pct' => number_format($indexRate, 3, '.', ''),
            'mrr_amount' => $mrr->roundHalfUp(),
            'arr_amount' => $mrr->multiply(12)->roundHalfUp(),
            'lifecycle_status' => $newStatus,
        ]);
        $sub->save();

        SubscriptionLifecycleTransitionedEvent::dispatch(
            $command->tenantId,
            (string) $sub->id,
            $action->value,
            $newStatus,
            $mrr->roundHalfUp(),
        );

        return $sub;
    }
}
