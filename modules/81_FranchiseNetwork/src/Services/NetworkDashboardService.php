<?php

declare(strict_types=1);

namespace Modules\FranchiseNetwork\Services;

use Carbon\CarbonImmutable;
use Modules\FranchiseNetwork\Domain\Enums\ActionStatus;
use Modules\FranchiseNetwork\Domain\Enums\AgreementStatus;
use Modules\FranchiseNetwork\Domain\Enums\AuditStatus;
use Modules\FranchiseNetwork\Domain\Enums\StoreStatus;
use Modules\FranchiseNetwork\Domain\Enums\TargetStatus;
use Modules\FranchiseNetwork\Domain\Support\Dec;
use Modules\FranchiseNetwork\Models\AuditAction;
use Modules\FranchiseNetwork\Models\CatalogPush;
use Modules\FranchiseNetwork\Models\CatalogPushTarget;
use Modules\FranchiseNetwork\Models\FranchiseAgreement;
use Modules\FranchiseNetwork\Models\FranchiseStore;
use Modules\FranchiseNetwork\Models\PurchaseRecord;
use Modules\FranchiseNetwork\Models\RoyaltyRun;
use Modules\FranchiseNetwork\Models\SellOutReport;
use Modules\FranchiseNetwork\Models\StoreAudit;

/**
 * Network KPIs of the franchisor command view: stores, net sell-out against the previous month and year, agreements that need
 * attention, billing run, push synchronisation health, audit results and purchase commitments.
 */
final class NetworkDashboardService
{
    public function __construct(private readonly AgreementService $agreements) {}

    /**
     * @return array<string, mixed>
     */
    public function overview(string $period): array
    {
        BillingPeriod::assert($period);
        $sellOut = fn (string $month): string => (string) Dec::round((string) (SellOutReport::query()->where('period', $month)->sum('net_sales')));
        $current = $sellOut($period);
        $previous = $sellOut(BillingPeriod::previous($period));
        $lastYear = $sellOut(BillingPeriod::sameMonthLastYear($period));

        $since = CarbonImmutable::today()->subDays(90)->toDateString();
        $audits = StoreAudit::query()->where('status', AuditStatus::Submitted->value)->whereDate('audited_on', '>=', $since)->get();

        return [
            'period' => $period,
            'stores' => [
                'total' => FranchiseStore::query()->count(),
                'active' => FranchiseStore::query()->where('status', StoreStatus::Active->value)->count(),
                'franchisee' => FranchiseStore::query()->where('store_type', 'franchisee')->count(),
                'directly_operated' => FranchiseStore::query()->where('store_type', 'dos')->count(),
                'not_linked' => FranchiseStore::query()->where('store_type', 'franchisee')->whereNull('franchisee_tenant_id')->count(),
                'reporting' => SellOutReport::query()->where('period', $period)->count(),
            ],
            'sell_out' => [
                'net' => $current,
                'previous_month' => $previous,
                'same_month_last_year' => $lastYear,
                'mom_percent' => Dec::cmp($previous, 0) > 0 ? Dec::round(Dec::mul(Dec::div(Dec::sub($current, $previous), $previous), 100)) : null,
                'yoy_percent' => Dec::cmp($lastYear, 0) > 0 ? Dec::round(Dec::mul(Dec::div(Dec::sub($current, $lastYear), $lastYear), 100)) : null,
            ],
            'agreements' => [
                'active' => FranchiseAgreement::query()->where('status', AgreementStatus::Active->value)->count(),
                'attention' => count($this->agreements->attentionList()),
            ],
            'billing' => $this->billing($period),
            'pushes' => [
                'recent' => CatalogPush::query()->orderByDesc('created_at')->limit(5)->get(['id', 'title', 'push_type', 'status', 'dispatched_at'])->map(static fn (CatalogPush $push): array => [
                    'id' => $push->id, 'title' => $push->title, 'push_type' => $push->push_type->value, 'status' => $push->status->value, 'dispatched_at' => $push->dispatched_at?->toIso8601String(),
                ])->all(),
                'failed_deliveries' => CatalogPushTarget::query()->where('status', TargetStatus::Failed->value)->count(),
                'awaiting_acknowledgement' => CatalogPushTarget::query()->where('status', TargetStatus::Synced->value)->count(),
            ],
            'audits' => [
                'submitted_90_days' => $audits->count(),
                'average_score_percent' => $audits->isEmpty() ? null : Dec::round(Dec::div($audits->reduce(static fn (string $sum, StoreAudit $audit): string => Dec::add($sum, $audit->score_percent), '0'), $audits->count())),
                'failed' => $audits->where('passed', false)->count(),
                'open_actions' => AuditAction::query()->where('status', ActionStatus::Open->value)->count(),
                'overdue_actions' => AuditAction::query()->where('status', ActionStatus::Open->value)->whereDate('due_date', '<', CarbonImmutable::today()->toDateString())->count(),
            ],
            'purchases' => [
                'non_compliant' => PurchaseRecord::query()->where('period', $period)->where('compliant', false)->count(),
            ],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function billing(string $period): ?array
    {
        $run = RoyaltyRun::query()->where('period', '<=', $period)->orderByDesc('period')->first();

        return $run === null ? null : [
            'period' => $run->period,
            'status' => $run->status->value,
            'stores_count' => $run->stores_count,
            'total_royalty' => $run->total_royalty,
            'total_marketing' => $run->total_marketing,
            'total_minimum_adjustment' => $run->total_minimum_adjustment,
            'total_loyalty_settlement' => $run->total_loyalty_settlement,
            'total_net' => $run->total_net,
            'currency' => $run->currency,
        ];
    }
}
