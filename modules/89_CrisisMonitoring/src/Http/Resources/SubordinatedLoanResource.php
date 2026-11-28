<?php

declare(strict_types=1);

namespace Modules\CrisisMonitoring\Http\Resources;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\CrisisMonitoring\Domain\Swiss\RangruecktrittCalculator;
use Modules\CrisisMonitoring\Models\SubordinatedLoanRangruecktritt;

/**
 * A subordinated loan with its accrued interest and its claim at the twelve-month horizon.
 *
 * @mixin SubordinatedLoanRangruecktritt
 */
final class SubordinatedLoanResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $claim = (new RangruecktrittCalculator)->claim([
            'principal' => (string) $this->principal,
            'interest_rate' => (string) $this->interest_rate,
            'interest_treatment' => (string) $this->interest_treatment,
            'scope' => (string) $this->scope,
            'effective_from' => CarbonImmutable::parse($this->effective_from),
            'deferral_end_date' => $this->deferral_end_date === null ? null : CarbonImmutable::parse($this->deferral_end_date),
        ], CarbonImmutable::today(), 12);

        return [
            'id' => (string) $this->id,
            'creditor_name' => (string) $this->creditor_name,
            'creditor_type' => (string) $this->creditor_type,
            'principal' => (string) $this->principal,
            'interest_rate' => (string) $this->interest_rate,
            'interest_treatment' => (string) $this->interest_treatment,
            'agreement_date' => CarbonImmutable::parse($this->agreement_date)->toDateString(),
            'effective_from' => CarbonImmutable::parse($this->effective_from)->toDateString(),
            'deferral_end_date' => $this->deferral_end_date === null ? null : CarbonImmutable::parse($this->deferral_end_date)->toDateString(),
            'scope' => (string) $this->scope,
            'status' => (string) $this->status,
            'notes' => $this->notes,
            'claim_at_horizon' => $claim['claim'],
            'accrued_interest' => $claim['accrued_interest'],
        ];
    }
}
