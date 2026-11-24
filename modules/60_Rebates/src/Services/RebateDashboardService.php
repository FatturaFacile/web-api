<?php

declare(strict_types=1);

namespace Modules\Rebates\Services;

use Illuminate\Support\Carbon;
use Modules\GeneralLedger\Services\Tax\Decimal;
use Modules\Rebates\Models\RebateAccrual;
use Modules\Rebates\Models\RebateAgreement;
use Modules\Rebates\Models\RebatePromotion;
use Modules\Rebates\Models\RebateSettlement;

/**
 * Totals of the rebates module: agreements by status, rebate accrued and not settled and rebate settled per direction,
 * promotions by state and the agreements about to end.
 */
final class RebateDashboardService
{
    /**
     * @return array<string, mixed>
     */
    public function summary(string $tenantId, Carbon $today): array
    {
        $agreements = RebateAgreement::query()->where('tenant_id', $tenantId)->get(['id', 'code', 'name', 'direction', 'status', 'ends_on']);
        $direction = $agreements->pluck('direction', 'id');
        $accrued = ['sales' => '0.00', 'purchase' => '0.00'];

        foreach (RebateAccrual::query()->where('tenant_id', $tenantId)->where('status', RebateAccrual::Accrued)->get(['agreement_id', 'rebate_amount']) as $accrual) {
            $side = $direction[$accrual->agreement_id] ?? null;

            if ($side !== null) {
                $accrued[$side] = Decimal::round2(Decimal::add($accrued[$side], (string) $accrual->rebate_amount));
            }
        }

        $settled = ['sales' => '0.00', 'purchase' => '0.00'];

        foreach (RebateSettlement::query()->where('tenant_id', $tenantId)->where('status', RebateSettlement::Settled)->get(['agreement_id', 'amount']) as $settlement) {
            $side = $direction[$settlement->agreement_id] ?? null;

            if ($side !== null) {
                $settled[$side] = Decimal::round2(Decimal::add($settled[$side], (string) $settlement->amount));
            }
        }

        $promotions = RebatePromotion::query()->where('tenant_id', $tenantId)->get();
        $states = $promotions->countBy(static fn (RebatePromotion $promotion): string => $promotion->stateOn($today));
        $limit = $today->copy()->addDays(30);

        return [
            'agreements' => ['total' => $agreements->count(), 'by_status' => $agreements->countBy('status')->all()],
            'accrued_open' => $accrued,
            'settled' => $settled,
            'promotions' => ['active' => (int) $states->get('active', 0), 'scheduled' => (int) $states->get('scheduled', 0), 'expired' => (int) $states->get('expired', 0)],
            'ending_agreements' => $agreements
                ->filter(static fn (RebateAgreement $agreement): bool => $agreement->status === RebateAgreement::Active && $agreement->ends_on->betweenIncluded($today, $limit))
                ->sortBy('ends_on')
                ->map(static fn (RebateAgreement $agreement): array => ['id' => $agreement->id, 'code' => $agreement->code, 'name' => $agreement->name, 'ends_on' => $agreement->ends_on->toDateString()])
                ->values()
                ->all(),
        ];
    }
}
