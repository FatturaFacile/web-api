<?php

declare(strict_types=1);

namespace Modules\CreditManagement\Services;

use Illuminate\Support\Carbon;
use Modules\CreditManagement\Contracts\ReceivablesLookupInterface;
use Modules\CreditManagement\Models\CreditCollectionCase;
use Modules\CreditManagement\Models\CreditDispute;
use Modules\CreditManagement\Models\CreditInsuranceCover;
use Modules\CreditManagement\Models\CreditInsurancePolicy;
use Modules\CreditManagement\Models\CreditLimitRequest;
use Modules\CreditManagement\Models\CreditPromiseToPay;
use Modules\CreditManagement\Models\CreditScore;
use Modules\GeneralLedger\Services\Tax\Decimal;

/**
 * Credit dashboard and collector worklist. DSO is the open receivables divided by the invoiced value of the last 90 days,
 * times 90 (null when nothing was invoiced). Overdue is split into the ageing buckets of ExposureService.
 */
final readonly class CreditDashboardService
{
    private const int DsoDays = 90;

    public function __construct(private ReceivablesLookupInterface $receivables, private ExposureService $exposures) {}

    /**
     * @return array<string, mixed>
     */
    public function summary(string $tenantId, Carbon $today): array
    {
        $aging = $this->exposures->aging($tenantId, $today);
        $receivables = $aging['total'];
        $current = '0.00';

        foreach ($aging['buckets'] as $bucket) {
            if ($bucket['key'] === 'current') {
                $current = $bucket['amount'];
            }
        }

        $overdue = Decimal::round2(Decimal::sub($receivables, $current));
        $invoiced = $this->receivables->invoicedBetween($tenantId, null, $today->copy()->subDays(self::DsoDays - 1)->toDateString(), $today->toDateString());
        $exposures = $this->exposures->listing($tenantId, $today);
        $cases = CreditCollectionCase::query()->where('tenant_id', $tenantId)->whereNotIn('status', CreditCollectionCase::ClosedStatuses)->get(['status', 'outstanding_amount']);
        $byStatus = array_fill_keys(CreditCollectionCase::Statuses, 0);
        $caseAmount = '0';

        foreach ($cases as $case) {
            $byStatus[$case->status]++;
            $caseAmount = Decimal::add($caseAmount, (string) $case->outstanding_amount);
        }

        $pending = CreditPromiseToPay::query()->where('tenant_id', $tenantId)->where('status', CreditPromiseToPay::Pending)->get(['promised_amount', 'promised_on']);
        $pendingAmount = '0';
        $pastDue = 0;

        foreach ($pending as $promise) {
            $pendingAmount = Decimal::add($pendingAmount, (string) $promise->promised_amount);
            $pastDue += $promise->promised_on->lt($today->copy()->startOfDay()) ? 1 : 0;
        }

        $disputes = CreditDispute::query()->where('tenant_id', $tenantId)->whereIn('status', CreditDispute::OpenStatuses)->get(['disputed_amount']);
        $disputeAmount = '0';

        foreach ($disputes as $dispute) {
            $disputeAmount = Decimal::add($disputeAmount, (string) $dispute->disputed_amount);
        }

        $topOverdue = array_slice(array_values(array_filter($exposures, static fn (array $row): bool => Decimal::isPositive($row['overdue_amount']))), 0, 5);
        usort($topOverdue, static fn (array $left, array $right): int => Decimal::compare($right['overdue_amount'], $left['overdue_amount']));

        return [
            'as_of' => $today->toDateString(),
            'receivables_total' => $receivables,
            'overdue_total' => $overdue,
            'overdue_percentage' => Decimal::isPositive($receivables) ? Decimal::round2(Decimal::mul(Decimal::div($overdue, $receivables), '100')) : '0.00',
            'invoiced_last_90_days' => $invoiced,
            'dso_days' => Decimal::isPositive($invoiced) ? Decimal::round2(Decimal::mul(Decimal::div($receivables, $invoiced), (string) self::DsoDays)) : null,
            'overdue_by_bucket' => $aging['buckets'],
            'customers_over_limit' => count(array_filter($exposures, static fn (array $row): bool => $row['is_over_limit'])),
            'cases' => ['open_count' => count($cases), 'outstanding_amount' => Decimal::round2($caseAmount), 'by_status' => $byStatus],
            'promises' => ['pending_count' => count($pending), 'pending_amount' => Decimal::round2($pendingAmount), 'past_due_count' => $pastDue],
            'disputes' => ['open_count' => count($disputes), 'open_amount' => Decimal::round2($disputeAmount)],
            'pending_limit_requests' => CreditLimitRequest::query()->where('tenant_id', $tenantId)->where('status', CreditLimitRequest::Pending)->count(),
            'rating_distribution' => $this->ratingDistribution($tenantId),
            'insurance' => $this->insurance($tenantId, $today),
            'top_overdue_customers' => array_map(static fn (array $row): array => ['customer_id' => $row['customer_id'], 'customer_name' => $row['customer_name'], 'overdue_amount' => $row['overdue_amount'], 'max_days_overdue' => $row['max_days_overdue']], $topOverdue),
        ];
    }

    /**
     * Cases still being worked, the ones with an action due first, then by priority and amount.
     *
     * @return list<array<string, mixed>>
     */
    public function worklist(string $tenantId, Carbon $today, ?string $collectorId, bool $dueOnly): array
    {
        $cases = CreditCollectionCase::query()
            ->with(['promises' => static fn ($query) => $query->where('status', CreditPromiseToPay::Pending), 'invoices'])
            ->where('tenant_id', $tenantId)
            ->whereNotIn('status', CreditCollectionCase::ClosedStatuses)
            ->when($collectorId !== null, static fn ($query) => $query->where('collector_id', $collectorId))
            ->get();
        $names = $this->receivables->customerNames($tenantId, $cases->pluck('customer_id')->map(static fn ($id): string => (string) $id)->all());
        $rank = ['high' => 0, 'normal' => 1, 'low' => 2];
        $rows = [];

        foreach ($cases as $case) {
            $isDue = $case->next_action_on !== null && $case->next_action_on->lte($today->copy()->startOfDay());

            if ($dueOnly && ! $isDue) {
                continue;
            }

            $oldest = $case->invoices->where('outstanding_amount', '>', 0)->whereNotNull('due_on')->min('due_on');
            $promise = $case->promises->first();
            $rows[] = [
                'id' => (string) $case->id,
                'case_number' => $case->case_number,
                'customer_id' => (string) $case->customer_id,
                'customer_name' => $names[(string) $case->customer_id] ?? '-',
                'status' => $case->status,
                'priority' => $case->priority,
                'collector_id' => $case->collector_id,
                'outstanding_amount' => $case->outstanding_amount,
                'currency' => $case->currency,
                'current_step_order' => $case->current_step_order,
                'next_action_on' => $case->next_action_on?->toDateString(),
                'is_action_due' => $isDue,
                'days_overdue' => $oldest === null ? 0 : ExposureService::daysOverdue($oldest instanceof Carbon ? $oldest->toDateString() : substr((string) $oldest, 0, 10), $today),
                'pending_promise' => $promise === null ? null : ['id' => (string) $promise->id, 'promised_amount' => $promise->promised_amount, 'promised_on' => $promise->promised_on->toDateString()],
            ];
        }

        usort($rows, static function (array $left, array $right) use ($rank): int {
            return [$left['is_action_due'] ? 0 : 1, $left['next_action_on'] ?? '9999-12-31', $rank[$left['priority']] ?? 1, -((float) $left['outstanding_amount'])]
                <=> [$right['is_action_due'] ? 0 : 1, $right['next_action_on'] ?? '9999-12-31', $rank[$right['priority']] ?? 1, -((float) $right['outstanding_amount'])];
        });

        return $rows;
    }

    /**
     * @return list<array{rating: string, count: int}>
     */
    private function ratingDistribution(string $tenantId): array
    {
        $latest = [];

        foreach (CreditScore::query()->where('tenant_id', $tenantId)->orderBy('scored_on')->orderBy('created_at')->get(['customer_id', 'rating']) as $score) {
            $latest[(string) $score->customer_id] = $score->rating;
        }

        $counts = array_count_values($latest);
        ksort($counts);

        return array_map(static fn (string $rating, int $count): array => ['rating' => $rating, 'count' => $count], array_keys($counts), array_values($counts));
    }

    /**
     * @return array<string, mixed>
     */
    private function insurance(string $tenantId, Carbon $today): array
    {
        $policies = CreditInsurancePolicy::query()->where('tenant_id', $tenantId)->where('status', CreditInsurancePolicy::Active)->whereDate('starts_on', '<=', $today->toDateString())->whereDate('ends_on', '>=', $today->toDateString())->get();
        $insured = '0';

        foreach (CreditInsuranceCover::query()->where('tenant_id', $tenantId)->where('status', CreditInsuranceCover::Granted)->whereIn('policy_id', $policies->pluck('id'))->get(['insured_limit']) as $cover) {
            $insured = Decimal::add($insured, (string) $cover->insured_limit);
        }

        return [
            'active_policies' => $policies->count(),
            'insured_total' => Decimal::round2($insured),
            'expiring_within_30_days' => $policies->filter(static fn (CreditInsurancePolicy $policy): bool => $policy->ends_on->lte($today->copy()->addDays(30)))->count(),
        ];
    }
}
