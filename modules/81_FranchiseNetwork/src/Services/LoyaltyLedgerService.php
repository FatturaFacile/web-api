<?php

declare(strict_types=1);

namespace Modules\FranchiseNetwork\Services;

use Carbon\CarbonImmutable;
use Modules\FranchiseNetwork\Domain\Enums\LoyaltyType;
use Modules\FranchiseNetwork\Domain\Support\Dec;
use Modules\FranchiseNetwork\Domain\Support\LoyaltySettlementCalculator;
use Modules\FranchiseNetwork\Exceptions\FranchiseNetworkException;
use Modules\FranchiseNetwork\Models\FranchiseStore;
use Modules\FranchiseNetwork\Models\LoyaltyTransaction;

/**
 * Cross-store loyalty ledger. Earning is recorded in the store of the sale; a redemption names the store where the points were
 * earned, so the franchisor can clear the difference between the stores in the monthly billing (see LoyaltySettlementCalculator).
 */
final class LoyaltyLedgerService
{
    /**
     * @param  array<string, mixed>  $data  customer_ref, type, points, earned_store_id, occurred_at
     */
    public function record(FranchiseStore $store, array $data, ?string $userId): LoyaltyTransaction
    {
        $type = LoyaltyType::from((string) $data['type']);
        $points = (int) $data['points'];

        if ($points < 1) {
            throw new FranchiseNetworkException('loyalty_points_invalid');
        }

        $earnedStoreId = $type === LoyaltyType::Redeem ? ($data['earned_store_id'] ?? $store->id) : null;
        $occurred = CarbonImmutable::parse((string) ($data['occurred_at'] ?? now()));

        if ($type === LoyaltyType::Redeem && $this->balance((string) $data['customer_ref']) < $points) {
            throw new FranchiseNetworkException('loyalty_balance_insufficient', 409);
        }

        return LoyaltyTransaction::query()->create([
            'store_id' => $store->id,
            'earned_store_id' => $earnedStoreId,
            'customer_ref' => (string) $data['customer_ref'],
            'type' => $type->value,
            'points' => $points,
            'value_amount' => Dec::round(Dec::mul($points, config('franchise_network.loyalty.point_value'))),
            'occurred_at' => $occurred,
            'period' => $occurred->format('Y-m'),
            'created_by' => $userId,
        ])->refresh();
    }

    /**
     * Points the customer holds across the whole network.
     */
    public function balance(string $customerRef): int
    {
        $rows = LoyaltyTransaction::query()->where('customer_ref', $customerRef)->get(['type', 'points']);

        return (int) $rows->sum(static fn (LoyaltyTransaction $row): int => $row->type === LoyaltyType::Earn ? $row->points : -$row->points);
    }

    /**
     * Net inter-store position of every store for redemptions of the month and before that are still to be settled.
     *
     * @return array<string, array{owed: string, receivable: string, net: string}>
     */
    public function positions(string $period, ?string $runId = null): array
    {
        return LoyaltySettlementCalculator::settle($this->openRedemptions($period, $runId));
    }

    /**
     * @return list<array{id: string, store_id: string, earned_store_id: string|null, value_amount: string}>
     */
    public function openRedemptions(string $period, ?string $runId = null): array
    {
        return LoyaltyTransaction::query()
            ->where('type', LoyaltyType::Redeem->value)
            ->where('period', '<=', $period)
            ->where(fn ($query) => $query->whereNull('settled_run_id')->when($runId, fn ($inner) => $inner->orWhere('settled_run_id', $runId)))
            ->get()
            ->map(static fn (LoyaltyTransaction $row): array => ['id' => (string) $row->id, 'store_id' => (string) $row->store_id, 'earned_store_id' => $row->earned_store_id === null ? null : (string) $row->earned_store_id, 'value_amount' => (string) $row->value_amount])
            ->all();
    }
}
