<?php

declare(strict_types=1);

namespace Modules\CRM\Services;

use Enterprise\AuditLedger\Contracts\AuditLedgerInterface;
use Enterprise\EventBus\Services\EventDispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\CRM\Contracts\CustomerHealthServiceInterface;
use Modules\CRM\DTOs\EvaluateHealthDTO;
use Modules\CRM\Events\CustomerHealthChangedEvent;
use Modules\CRM\Models\Account;
use Modules\CRM\Models\Activity;
use Modules\CRM\Models\CustomerHealthProfile;
use Modules\CRM\Models\Opportunity;

final readonly class CustomerHealthService implements CustomerHealthServiceInterface
{
    public function __construct(
        private EventDispatcher $dispatcher,
        private AuditLedgerInterface $auditLedger
    ) {}

    public function evaluateHealth(Account $account, ?EvaluateHealthDTO $dto = null): CustomerHealthProfile
    {
        return DB::transaction(function () use ($account, $dto) {
            $lockedAccount = Account::where('id', $account->id)->lockForUpdate()->firstOrFail();
            $tenantId = (string) $lockedAccount->tenant_id;

            // 1. Activity Score (0-100)
            $completedActivities = $dto?->completedActivitiesCount ?? Activity::where('tenant_id', $tenantId)
                ->where('related_type', 'account')
                ->where('related_id', $lockedAccount->id)
                ->where('status', 'completed')
                ->count();

            $activityScore = min(100.0, max(10.0, (float) ($completedActivities * 15.0)));

            // 2. Engagement Score (0-100)
            $recentActivitiesCount = Activity::where('tenant_id', $tenantId)
                ->where('related_type', 'account')
                ->where('related_id', $lockedAccount->id)
                ->where('created_at', '>=', now()->subDays(30))
                ->count();

            $engagementScore = min(100.0, max(15.0, (float) ($recentActivitiesCount * 25.0)));

            // 3. Revenue Score (0-100)
            $wonOpportunitiesSum = (float) Opportunity::where('tenant_id', $tenantId)
                ->where('account_id', $lockedAccount->id)
                ->where('status', 'won')
                ->sum('amount');

            $revenueScore = $wonOpportunitiesSum >= 100000.0 ? 100.0 : ($wonOpportunitiesSum >= 30000.0 ? 80.0 : ($wonOpportunitiesSum > 0 ? 60.0 : 40.0));

            // 4. Risk Score (0-100)
            $daysSinceInteraction = $dto?->daysSinceLastInteraction ?? ($lockedAccount->last_activity_at ? now()->diffInDays($lockedAccount->last_activity_at) : 90);
            $overdueReceivables = $dto?->overdueReceivableAmount ?? 0.0;

            $riskScore = 10.0;
            if ($daysSinceInteraction > 90) {
                $riskScore += 40.0;
            } elseif ($daysSinceInteraction > 45) {
                $riskScore += 20.0;
            }

            if ($overdueReceivables > 10000.0) {
                $riskScore += 40.0;
            } elseif ($overdueReceivables > 0.0) {
                $riskScore += 20.0;
            }
            $riskScore = min(100.0, $riskScore);

            // 5. Composite Health Index (0-100)
            $healthIndex = round(
                ($activityScore * 0.25) +
                ($engagementScore * 0.25) +
                ($revenueScore * 0.30) +
                ((100.0 - $riskScore) * 0.20),
                2
            );

            // 6. Churn Risk Indicator
            $churnRisk = 'low';
            if ($healthIndex < 40.0 || $riskScore >= 75.0) {
                $churnRisk = 'critical';
            } elseif ($healthIndex < 60.0 || $riskScore >= 50.0) {
                $churnRisk = 'high';
            } elseif ($healthIndex < 80.0 || $riskScore >= 30.0) {
                $churnRisk = 'medium';
            }

            // 7. Update or Create CustomerHealthProfile
            $profile = CustomerHealthProfile::updateOrCreate(
                ['tenant_id' => $tenantId, 'account_id' => $lockedAccount->id],
                [
                    'id' => (string) Str::uuid7(),
                    'activity_score' => $activityScore,
                    'engagement_score' => $engagementScore,
                    'revenue_score' => $revenueScore,
                    'risk_score' => $riskScore,
                    'churn_risk_indicator' => $churnRisk,
                    'health_index' => $healthIndex,
                    'last_interaction_at' => $lockedAccount->last_activity_at,
                    'open_receivable_amount' => $dto?->openReceivableAmount ?? 0.00,
                    'overdue_receivable_amount' => $overdueReceivables,
                    'evaluation_metrics' => [
                        'evaluated_at' => now()->toIso8601String(),
                        'completed_activities' => $completedActivities,
                        'days_since_interaction' => $daysSinceInteraction,
                    ],
                ]
            );

            // 8. Sync Account Level Health Fields
            $lockedAccount->update([
                'customer_health_index' => $healthIndex,
                'churn_risk' => $churnRisk,
            ]);

            // 9. Audit Trail
            $this->auditLedger->record(
                action: 'CUSTOMER_HEALTH_EVALUATED',
                model: $lockedAccount,
                payloadBefore: null,
                payloadAfter: [
                    'account_id' => (string) $lockedAccount->id,
                    'health_index' => $healthIndex,
                    'churn_risk' => $churnRisk,
                    'activity_score' => $activityScore,
                    'engagement_score' => $engagementScore,
                    'revenue_score' => $revenueScore,
                    'risk_score' => $riskScore,
                ],
                moduleCode: 'MODULE_02_CRM'
            );

            // 10. Outbox Domain Event
            $event = new CustomerHealthChangedEvent(
                accountId: (string) $lockedAccount->id,
                healthIndex: $healthIndex,
                churnRiskIndicator: $churnRisk,
                activityScore: $activityScore,
                engagementScore: $engagementScore,
                revenueScore: $revenueScore,
                riskScore: $riskScore,
                tenantId: $tenantId,
                companyId: $tenantId
            );
            $this->dispatcher->publish($event);
            event($event);

            return $profile;
        });
    }

    public function recalculateTenantHealth(string $tenantId): int
    {
        $accounts = Account::where('tenant_id', $tenantId)->get();
        foreach ($accounts as $account) {
            $this->evaluateHealth($account);
        }

        return $accounts->count();
    }
}
