<?php

declare(strict_types=1);

namespace Modules\CRM\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\CRM\Models\MdfClaim;
use Modules\CRM\Models\MdfFund;
use Modules\CRM\Models\Partner;

/**
 * Market development funds: one fund per partner and fiscal year, spent by claims. Approved and paid claims commit the
 * fund; a claim can never exceed what is left.
 */
final class MdfService
{
    /**
     * Creates the fund of a year. Without an amount it accrues the MDF percentage of the partner tier on its closed
     * revenue.
     */
    public function createFund(Partner $partner, int $fiscalYear, ?string $allocatedAmount, string $currency = 'EUR'): MdfFund
    {
        if (MdfFund::query()->where('tenant_id', $partner->tenant_id)->where('partner_id', $partner->id)->where('fiscal_year', $fiscalYear)->exists()) {
            throw ValidationException::withMessages(['fiscal_year' => 'The partner already has a fund for this year.']);
        }

        if ($allocatedAmount === null) {
            $tier = $partner->tier;

            if ($tier === null) {
                throw ValidationException::withMessages(['allocated_amount' => 'Give an amount: the partner has no tier to accrue from.']);
            }

            $allocatedAmount = bcdiv(bcmul((string) $partner->closed_revenue, (string) $tier->mdf_percent, 4), '100', 2);
        }

        return MdfFund::create(['tenant_id' => $partner->tenant_id, 'partner_id' => $partner->id, 'fiscal_year' => $fiscalYear, 'allocated_amount' => $allocatedAmount, 'currency' => $currency]);
    }

    /**
     * @return array{allocated: string, committed: string, pending: string, remaining: string}
     */
    public function summary(MdfFund $fund): array
    {
        $committed = $this->sumClaims($fund, ['approved', 'paid']);
        $allocated = number_format((float) $fund->allocated_amount, 2, '.', '');

        return [
            'allocated' => $allocated,
            'committed' => $committed,
            'pending' => $this->sumClaims($fund, ['submitted']),
            'remaining' => bcsub($allocated, $committed, 2),
        ];
    }

    /**
     * @param  array{activity: string, amount: string|float|int, activity_date?: string|null}  $data
     */
    public function submitClaim(MdfFund $fund, array $data): MdfClaim
    {
        $this->assertWithinRemaining($fund, (string) $data['amount']);

        return MdfClaim::create([
            'tenant_id' => $fund->tenant_id,
            'fund_id' => $fund->id,
            'activity' => $data['activity'],
            'amount' => $data['amount'],
            'activity_date' => $data['activity_date'] ?? null,
            'status' => 'submitted',
        ]);
    }

    public function approve(MdfClaim $claim, string $userId): MdfClaim
    {
        $this->assertStatus($claim, ['submitted']);
        $this->assertWithinRemaining($claim->fund, (string) $claim->amount);

        return $this->decide($claim, 'approved', $userId, null);
    }

    public function reject(MdfClaim $claim, string $userId, string $reason): MdfClaim
    {
        $this->assertStatus($claim, ['submitted']);

        return $this->decide($claim, 'rejected', $userId, $reason);
    }

    public function markPaid(MdfClaim $claim): MdfClaim
    {
        $this->assertStatus($claim, ['approved']);
        $claim->forceFill(['status' => 'paid', 'paid_at' => now()])->save();

        return $claim;
    }

    /**
     * @param  list<string>  $statuses
     */
    private function sumClaims(MdfFund $fund, array $statuses): string
    {
        return number_format((float) MdfClaim::query()->where('tenant_id', $fund->tenant_id)->where('fund_id', $fund->id)->whereIn('status', $statuses)->sum('amount'), 2, '.', '');
    }

    private function assertWithinRemaining(MdfFund $fund, string $amount): void
    {
        if (bccomp($amount, $this->summary($fund)['remaining'], 2) > 0) {
            throw ValidationException::withMessages(['amount' => 'The amount exceeds what is left in the fund.']);
        }
    }

    /**
     * @param  list<string>  $allowed
     */
    private function assertStatus(MdfClaim $claim, array $allowed): void
    {
        if (! in_array($claim->status, $allowed, true)) {
            throw ValidationException::withMessages(['status' => 'The claim is '.$claim->status.' and cannot take this action.']);
        }
    }

    private function decide(MdfClaim $claim, string $status, string $userId, ?string $reason): MdfClaim
    {
        return DB::transaction(function () use ($claim, $status, $userId, $reason): MdfClaim {
            $claim->forceFill(['status' => $status, 'decided_by_user_id' => $userId, 'decided_at' => now(), 'decision_reason' => $reason])->save();

            return $claim;
        });
    }
}
