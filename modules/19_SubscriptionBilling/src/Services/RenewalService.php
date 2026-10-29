<?php

declare(strict_types=1);

namespace Modules\SubscriptionBilling\Services;

use Carbon\Carbon;
use Enterprise\AuditLedger\Contracts\AuditLedgerInterface;
use Enterprise\EventBus\Services\EventDispatcher;
use Illuminate\Support\Facades\DB;
use Modules\SubscriptionBilling\Contracts\RenewalServiceInterface;
use Modules\SubscriptionBilling\Events\RenewalForecastEvent;
use Modules\SubscriptionBilling\Events\SubscriptionRenewedEvent;
use Modules\SubscriptionBilling\Models\Subscription;
use Modules\SubscriptionBilling\Models\SubscriptionVersion;

final readonly class RenewalService implements RenewalServiceInterface
{
    public function __construct(
        private EventDispatcher $dispatcher,
        private AuditLedgerInterface $auditLedger
    ) {}

    public function processExpiringSubscriptions(string $tenantId, int $lookaheadDays = 30): array
    {
        return DB::transaction(function () use ($tenantId, $lookaheadDays) {
            $threshold = now()->addDays($lookaheadDays)->toDateString();

            $expiringSubs = Subscription::with(['plan', 'items'])
                ->where('tenant_id', $tenantId)
                ->where('status', 'active')
                ->where('current_period_end', '<=', $threshold)
                ->lockForUpdate()
                ->get();

            $renewed = [];
            $opportunities = [];
            $projectedRenewalValue = 0.00;

            foreach ($expiringSubs as $sub) {
                $mrr = (float) $sub->mrr;
                $projectedRenewalValue += ($mrr * 12.0);

                if ($sub->auto_renew) {
                    $renewed[] = $this->autoRenewSubscription($sub);
                } else {
                    $opportunities[] = $this->generateRenewalOpportunity($sub);
                }
            }

            // Dispatch Treasury Renewal Forecast Event
            $this->dispatcher->dispatch(new RenewalForecastEvent(
                forecastPeriod: now()->format('Y-m'),
                projectedRenewalsCount: $expiringSubs->count(),
                projectedRenewalValue: round($projectedRenewalValue, 2),
                renewalProbabilityRate: 0.92,
                currency: 'EUR',
                tenantId: $tenantId,
                companyId: $tenantId
            ));

            return [
                'total_expiring' => $expiringSubs->count(),
                'auto_renewed_count' => count($renewed),
                'opportunities_generated' => count($opportunities),
                'auto_renewed' => $renewed,
                'opportunities' => $opportunities,
            ];
        });
    }

    public function autoRenewSubscription(Subscription $subscription): Subscription
    {
        $currentEnd = Carbon::parse($subscription->current_period_end);
        $termMonths = max(1, (int) $subscription->contract_term_months);

        $newPeriodStart = $currentEnd->copy()->addDay();
        $newPeriodEnd = $newPeriodStart->copy()->addMonths($termMonths)->subDay();

        $subscription->update([
            'current_period_start' => $newPeriodStart->toDateString(),
            'current_period_end' => $newPeriodEnd->toDateString(),
            'status' => 'active',
        ]);

        $latestVersion = (int) $subscription->versions()->max('version_number');
        $mrr = (float) $subscription->mrr;

        SubscriptionVersion::create([
            'tenant_id' => $subscription->tenant_id,
            'subscription_id' => $subscription->id,
            'version_number' => $latestVersion + 1,
            'effective_date' => $newPeriodStart->toDateString(),
            'change_type' => 'renewal',
            'mrr_before' => $mrr,
            'mrr_after' => $mrr,
            'mrr_delta' => 0.00,
            'proration_amount' => 0.00,
            'snapshot_payload' => $subscription->toArray(),
            'change_reason' => 'Automated contractual evergreen renewal',
        ]);

        $this->auditLedger->record(
            'SUBSCRIPTION_AUTO_RENEWED',
            $subscription,
            ['old_end' => $currentEnd->toDateString()],
            ['new_start' => $newPeriodStart->toDateString(), 'new_end' => $newPeriodEnd->toDateString()],
            'MODULE_19_SUB'
        );

        $this->dispatcher->dispatch(new SubscriptionRenewedEvent(
            subscriptionId: $subscription->id,
            subscriptionNumber: $subscription->subscription_number,
            newPeriodStart: $newPeriodStart->toDateString(),
            newPeriodEnd: $newPeriodEnd->toDateString(),
            renewalAmount: (float) $subscription->arr,
            tenantId: $subscription->tenant_id,
            companyId: (string) ($subscription->company_id ?? $subscription->tenant_id)
        ));

        return $subscription;
    }

    public function generateRenewalOpportunity(Subscription $subscription): array
    {
        return [
            'subscription_id' => $subscription->id,
            'customer_id' => $subscription->customer_id,
            'opportunity_type' => 'renewal',
            'title' => 'Contract Renewal: '.$subscription->subscription_number,
            'estimated_value' => (float) $subscription->arr,
            'target_close_date' => $subscription->current_period_end ? Carbon::parse($subscription->current_period_end)->toDateString() : now()->toDateString(),
            'status' => 'identified',
        ];
    }
}
