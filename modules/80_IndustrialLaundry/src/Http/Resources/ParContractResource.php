<?php

declare(strict_types=1);

namespace Modules\IndustrialLaundry\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\IndustrialLaundry\Models\ParContract;
use Modules\IndustrialLaundry\Models\ParContractLine;
use Modules\IndustrialLaundry\Support\ContactName;

/**
 * A customer linen par stock contract with its article lines.
 *
 * @mixin ParContract
 */
final class ParContractResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'customer_id' => $this->customer_id,
            'customer' => $this->relationLoaded('customer') ? ContactName::summary($this->customer) : null,
            'customer_segment' => $this->customer_segment->value,
            'valid_from' => $this->valid_from->toDateString(),
            'valid_to' => $this->valid_to?->toDateString(),
            'is_active' => $this->is_active,
            'billing_model' => $this->billing_model->value,
            'kg_basis' => $this->kg_basis->value,
            'unreturned_garment_replacement_price' => (string) $this->unreturned_garment_replacement_price,
            'unreturned_threshold_days' => $this->unreturned_threshold_days,
            'jurisdiction' => $this->jurisdiction->value,
            'currency' => $this->currency,
            'tax_rate' => (string) $this->tax_rate,
            'notes' => $this->notes,
            'lines' => $this->relationLoaded('lines') ? $this->lines->sortBy(fn (ParContractLine $line): string => $line->article_type->value)->map(static fn (ParContractLine $line): array => [
                'id' => (string) $line->id,
                'article_type' => $line->article_type->value,
                'product_id' => $line->product_id,
                'label' => $line->label,
                'circulating_stock_qty' => $line->circulating_stock_qty,
                'weekly_min_billing_percentage' => (string) $line->weekly_min_billing_percentage,
                'seasonal_min_billing_percentage' => $line->seasonal_min_billing_percentage !== null ? (string) $line->seasonal_min_billing_percentage : null,
                'season_start_month' => $line->season_start_month,
                'season_end_month' => $line->season_end_month,
                'unit_wash_price' => (string) $line->unit_wash_price,
                'price_per_kg' => (string) $line->price_per_kg,
                'rental_price' => (string) $line->rental_price,
                'replacement_price' => (string) $line->replacement_price,
            ])->values()->all() : null,
        ];
    }
}
