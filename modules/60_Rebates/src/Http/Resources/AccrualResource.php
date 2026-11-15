<?php

declare(strict_types=1);

namespace Modules\Rebates\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Rebates\Models\RebateAccrual;

/**
 * One accrual run of an agreement.
 *
 * @mixin RebateAccrual
 */
final class AccrualResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'agreement_id' => $this->agreement_id,
            'as_of' => $this->as_of->toDateString(),
            'base_amount' => $this->base_amount,
            'cumulative_rebate' => $this->cumulative_rebate,
            'rebate_amount' => $this->rebate_amount,
            'applied_rate_percent' => $this->applied_rate_percent,
            'status' => $this->status,
            'settlement_id' => $this->settlement_id,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
