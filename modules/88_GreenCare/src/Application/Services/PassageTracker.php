<?php

declare(strict_types=1);

namespace Modules\GreenCare\Application\Services;

use Modules\GreenCare\Domain\Enums\BillingMode;
use Modules\GreenCare\Domain\Enums\BillingStatus;
use Modules\GreenCare\Models\CrewRouteStop;
use Modules\GreenCare\Models\SeasonalMaintenanceContract;

/**
 * Counts the passages of a seasonal contract. The passage that goes beyond the passages included in the contract (the 17th of 16) is an
 * extra passage: it is flagged and becomes ready to invoice at the extra passage price. A time and material contract bills the hours, never
 * the passage; the others keep the passage inside the contract until the included passages are used.
 */
final class PassageTracker
{
    /**
     * Registers the passage of a completed stop on its contract. The contract row is locked, so two crews closing stops at the same
     * time get two different passage numbers.
     */
    public function register(CrewRouteStop $stop): ?SeasonalMaintenanceContract
    {
        if ($stop->contract_id === null || ! $stop->counts_toward_contract_passage) {
            return null;
        }

        /** @var SeasonalMaintenanceContract $contract */
        $contract = SeasonalMaintenanceContract::query()->whereKey($stop->contract_id)->lockForUpdate()->firstOrFail();
        $number = $contract->completed_passages_count + 1;
        $contract->completed_passages_count = $number;
        $contract->save();

        $extra = $contract->billing_mode !== BillingMode::TimeAndMaterial && $number > $contract->included_passages_count;

        $stop->passage_number = $number;
        $stop->is_extra_passage = $extra;
        $stop->extra_passage_amount = $extra ? $contract->extra_passage_unit_price : '0.00';
        $stop->billing_status = match (true) {
            $contract->billing_mode === BillingMode::TimeAndMaterial => BillingStatus::ReadyToInvoice,
            $extra && $contract->auto_bill_extra_passages => BillingStatus::ReadyToInvoice,
            $extra => BillingStatus::NotBilled,
            default => BillingStatus::IncludedInContract,
        };

        return $contract;
    }

    /**
     * The visual counter of the contract ("passage 8 of 16", "17 of 16 - extra to invoice").
     *
     * @return array{completed: int, included: int, extra: int, is_over_included: bool, percent: float}
     */
    public function counter(SeasonalMaintenanceContract $contract): array
    {
        $completed = $contract->completed_passages_count;
        $included = $contract->included_passages_count;

        return [
            'completed' => $completed,
            'included' => $included,
            'extra' => $contract->extraPassagesCount(),
            'is_over_included' => $included > 0 ? $completed > $included : false,
            'percent' => $included > 0 ? round(min(100, $completed / $included * 100), 1) : 0.0,
        ];
    }
}
