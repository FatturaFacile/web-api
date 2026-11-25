<?php

declare(strict_types=1);

namespace Modules\FranchiseNetwork\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\FranchiseNetwork\Models\LoyaltyTransaction;

/**
 * @mixin LoyaltyTransaction
 */
final class LoyaltyTransactionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'store_id' => $this->store_id,
            'earned_store_id' => $this->earned_store_id,
            'customer_ref' => $this->customer_ref,
            'type' => $this->type->value,
            'points' => $this->points,
            'value_amount' => $this->value_amount,
            'occurred_at' => $this->occurred_at->toIso8601String(),
            'period' => $this->period,
            'settled' => $this->settled_run_id !== null,
        ];
    }
}
