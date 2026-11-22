<?php

declare(strict_types=1);

namespace Modules\Rebates\Services;

use Illuminate\Validation\ValidationException;
use Modules\Rebates\Models\RebateAgreement;
use Modules\Rebates\Models\RebateContribution;

/**
 * Marketing, co-marketing and listing contributions of an agreement. Only a planned contribution can change, and only
 * while the agreement is a draft or active; accrued and settled ones belong to the accruals already booked.
 */
final class RebateContributionService
{
    /**
     * @param  array{kind: string, description: string, amount: string|float|int, due_on: string}  $data
     */
    public function add(RebateAgreement $agreement, array $data): RebateContribution
    {
        $this->assertOpen($agreement);

        return RebateContribution::create([...$data, 'tenant_id' => $agreement->tenant_id, 'agreement_id' => $agreement->id, 'status' => RebateContribution::Planned]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(RebateContribution $contribution, array $data): RebateContribution
    {
        $this->assertPlanned($contribution);
        $contribution->update($data);

        return $contribution->refresh();
    }

    public function remove(RebateContribution $contribution): void
    {
        $this->assertPlanned($contribution);
        $contribution->delete();
    }

    private function assertOpen(RebateAgreement $agreement): void
    {
        if (! in_array($agreement->status, [RebateAgreement::Draft, RebateAgreement::Active], true)) {
            throw ValidationException::withMessages(['agreement' => [__('rebate_errors.contribution.agreement_closed')]]);
        }
    }

    private function assertPlanned(RebateContribution $contribution): void
    {
        if ($contribution->status !== RebateContribution::Planned) {
            throw ValidationException::withMessages(['contribution' => [__('rebate_errors.contribution.locked')]]);
        }
    }
}
