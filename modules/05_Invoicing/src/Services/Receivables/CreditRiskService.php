<?php

declare(strict_types=1);

namespace Modules\Invoicing\Services\Receivables;

use Carbon\CarbonInterface;
use Modules\GeneralLedger\Services\Tax\Decimal;
use Modules\Invoicing\Models\ReceivableItem;

/**
 * Composite credit risk of a customer, a score from 0 (safe) to 100 (critical) made of six factors, each with its
 * points: utilisation of the credit limit (25), share of the open amount that is overdue (25), the oldest overdue
 * item (25), the average lateness of the payments of the last twelve months (15), the share of the exposure the credit
 * insurance does not cover (5) and an active credit block (5). Rating: below 25 low, below 50 medium, below 75 high,
 * else critical.
 */
final class CreditRiskService
{
    public function __construct(
        private readonly CreditLimitService $limits,
    ) {}

    /**
     * @return array{customer_id: string, score: int, rating: string, factors: list<array{key: string, value: string|int|null, points: float}>, blocked: bool}
     */
    public function assess(string $tenantId, string $customerId, CarbonInterface $today): array
    {
        $status = $this->limits->status($tenantId, $customerId);
        $exposure = $status['exposure'];
        $open = $exposure['open_invoices'];
        $utilization = $status['utilization_percent'];
        $overdueShare = Decimal::isPositive($open) ? (float) Decimal::div($exposure['overdue_amount'], $open) : 0.0;
        $lateness = $this->averageDaysLate($tenantId, $customerId, $today);
        $totalExposure = $exposure['total_exposure'];
        $uninsuredShare = Decimal::isPositive($totalExposure) ? (float) Decimal::div($status['uninsured_exposure'], $totalExposure) : 0.0;
        $blocked = (bool) $status['is_blocked'];

        $utilizationPoints = $utilization === null ? 0.0 : min(25.0, max(0.0, ((float) $utilization - 70.0) / 30.0 * 20.0) + ((float) $utilization > 100.0 ? 5.0 : 0.0));
        $factors = [
            ['key' => 'credit_utilization', 'value' => $utilization, 'points' => round($utilizationPoints, 2)],
            ['key' => 'overdue_share', 'value' => number_format($overdueShare * 100, 2, '.', ''), 'points' => round(min(1.0, $overdueShare) * 25.0, 2)],
            ['key' => 'max_days_past_due', 'value' => $exposure['max_days_past_due'], 'points' => round(min(120, $exposure['max_days_past_due']) / 120 * 25.0, 2)],
            ['key' => 'payment_lateness_days', 'value' => number_format($lateness, 2, '.', ''), 'points' => round(min(60.0, $lateness) / 60.0 * 15.0, 2)],
            ['key' => 'uninsured_share', 'value' => number_format($uninsuredShare * 100, 2, '.', ''), 'points' => round($uninsuredShare * 5.0, 2)],
            ['key' => 'credit_block', 'value' => $blocked ? 1 : 0, 'points' => $blocked ? 5.0 : 0.0],
        ];

        $score = (int) round(min(100.0, array_sum(array_column($factors, 'points'))));

        return [
            'customer_id' => $customerId,
            'score' => $score,
            'rating' => match (true) {
                $score < 25 => 'low',
                $score < 50 => 'medium',
                $score < 75 => 'high',
                default => 'critical',
            },
            'factors' => $factors,
            'blocked' => $blocked,
        ];
    }

    /**
     * Average days between the due date and the value date of the items settled in the last twelve months; early
     * payments count as 0.
     */
    private function averageDaysLate(string $tenantId, string $customerId, CarbonInterface $today): float
    {
        $paid = ReceivableItem::query()
            ->where('tenant_id', $tenantId)
            ->where('customer_id', $customerId)
            ->where('status', ReceivableItem::StatusPaid)
            ->whereNotNull('value_date')
            ->whereDate('value_date', '>=', $today->copy()->subYear()->toDateString())
            ->get();

        if ($paid->isEmpty()) {
            return 0.0;
        }

        $days = $paid->sum(static fn (ReceivableItem $item): int => $item->value_date->gt($item->due_date) ? (int) $item->due_date->diffInDays($item->value_date, true) : 0);

        return $days / $paid->count();
    }
}
