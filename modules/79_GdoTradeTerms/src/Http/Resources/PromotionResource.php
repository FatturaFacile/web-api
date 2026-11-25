<?php

declare(strict_types=1);

namespace Modules\GdoTradeTerms\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\GdoTradeTerms\Models\GdoPromotion;

/**
 * A trade promotion or contribution of a contract.
 *
 * @mixin GdoPromotion
 */
final class PromotionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'contract_id' => $this->contract_id,
            'code' => $this->code,
            'name' => $this->name,
            'promotion_type' => $this->promotion_type->value,
            'treatment' => $this->promotion_type->treatment()->value,
            'date_basis' => $this->date_basis->value,
            'starts_on' => $this->starts_on->toDateString(),
            'ends_on' => $this->ends_on->toDateString(),
            'contribution_mode' => $this->contribution_mode->value,
            'rate_percent' => $this->rate_percent,
            'unit_amount' => $this->unit_amount,
            'fixed_amount' => $this->fixed_amount,
            'fixed_accrual' => $this->fixed_accrual->value,
            'cap_amount' => $this->cap_amount,
            'category_ids' => $this->category_ids ?? [],
            'brand_ids' => $this->brand_ids ?? [],
            'product_ids' => $this->product_ids ?? [],
            'delivery_point_ids' => $this->delivery_point_ids ?? [],
            'status' => $this->status->value,
            'notes' => $this->notes,
            'contract' => $this->whenLoaded('contract', fn (): array => ['id' => $this->contract->id, 'code' => $this->contract->code, 'name' => $this->contract->name]),
        ];
    }
}
