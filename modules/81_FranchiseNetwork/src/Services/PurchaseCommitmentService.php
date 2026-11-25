<?php

declare(strict_types=1);

namespace Modules\FranchiseNetwork\Services;

use Modules\FranchiseNetwork\Domain\Enums\AgreementStatus;
use Modules\FranchiseNetwork\Domain\Support\Dec;
use Modules\FranchiseNetwork\Models\FranchiseAgreement;
use Modules\FranchiseNetwork\Models\FranchiseStore;
use Modules\FranchiseNetwork\Models\PurchaseRecord;

/**
 * Tracks the minimum monthly purchases the agreement obliges the store to make from the central warehouse or the approved suppliers.
 */
final class PurchaseCommitmentService
{
    public function record(FranchiseStore $store, string $period, string|float $purchased, ?string $userId): PurchaseRecord
    {
        $agreement = $this->agreementFor($store, $period);
        $required = Dec::round($agreement->min_purchase_amount ?? 0);
        $values = [
            'purchased_amount' => Dec::round($purchased),
            'required_amount' => $required,
            'compliant' => Dec::cmp($purchased, $required) >= 0,
        ];

        $record = PurchaseRecord::query()->where('store_id', $store->id)->where('period', $period)->first();

        if ($record === null) {
            return PurchaseRecord::query()->create([...$values, 'store_id' => $store->id, 'period' => $period, 'created_by' => $userId])->refresh();
        }

        $record->update([...$values, 'updated_by' => $userId]);

        return $record->refresh();
    }

    public function agreementFor(FranchiseStore $store, string $period): ?FranchiseAgreement
    {
        [$from, $to] = BillingPeriod::bounds($period);

        return FranchiseAgreement::query()
            ->where('store_id', $store->id)
            ->whereIn('status', [AgreementStatus::Active->value, AgreementStatus::Renewed->value, AgreementStatus::Expired->value, AgreementStatus::Terminated->value])
            ->whereDate('start_date', '<=', $to->toDateString())
            ->whereDate('end_date', '>=', $from->toDateString())
            ->orderByDesc('start_date')
            ->first();
    }
}
