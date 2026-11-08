<?php

declare(strict_types=1);

namespace Modules\AssetManagement\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\AssetManagement\Models\AssetBook;

/**
 * One parallel book of an asset with its parameters and values. Amounts are decimal strings.
 *
 * @mixin AssetBook
 */
final class AssetBookResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'asset_id' => $this->asset_id,
            'ledger_type' => $this->ledger_type,
            'depreciation_method' => $this->depreciation_method,
            'useful_life_months' => $this->useful_life_months !== null ? (int) $this->useful_life_months : null,
            'rate_percent' => $this->rate_percent !== null ? (string) $this->rate_percent : null,
            'declining_balance_factor' => $this->declining_balance_factor !== null ? (string) $this->declining_balance_factor : null,
            'pro_rata_convention' => $this->pro_rata_convention,
            'depreciation_start_date' => $this->depreciation_start_date?->format('Y-m-d'),
            'cost' => $this->cost !== null ? (string) $this->cost : null,
            'salvage_value' => $this->salvage_value !== null ? (string) $this->salvage_value : null,
            'depreciable_base' => $this->depreciable_base !== null ? (string) $this->depreciable_base : null,
            'accumulated_depreciation' => $this->accumulated_depreciation !== null ? (string) $this->accumulated_depreciation : null,
            'net_book_value' => $this->net_book_value !== null ? (string) $this->net_book_value : null,
            'total_expected_units' => $this->total_expected_units !== null ? (string) $this->total_expected_units : null,
            'consumed_units' => $this->consumed_units !== null ? (string) $this->consumed_units : null,
            'last_period_code' => $this->last_period_code,
            'is_fully_depreciated' => (bool) $this->is_fully_depreciated,
            'posts_to_gl' => (bool) $this->posts_to_gl,
        ];
    }
}
