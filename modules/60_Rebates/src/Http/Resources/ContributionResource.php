<?php

declare(strict_types=1);

namespace Modules\Rebates\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Rebates\Models\RebateContribution;

/**
 * A marketing, co-marketing or listing contribution of a supplier rebate agreement.
 *
 * @mixin RebateContribution
 */
final class ContributionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'agreement_id' => $this->agreement_id,
            'kind' => $this->kind,
            'description' => $this->description,
            'amount' => $this->amount,
            'due_on' => $this->due_on->toDateString(),
            'status' => $this->status,
            'accrual_id' => $this->accrual_id,
        ];
    }
}
