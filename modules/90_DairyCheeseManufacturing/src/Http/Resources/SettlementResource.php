<?php

declare(strict_types=1);

namespace Modules\DairyCheeseManufacturing\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\DairyCheeseManufacturing\Models\MonthlySettlement;

/**
 * @mixin MonthlySettlement
 */
final class SettlementResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'breeder_id' => $this->breeder_id, 'breeder_name' => $this->breeder->name, 'period' => $this->period, 'grid_id' => $this->grid_id, 'status' => $this->status,
            'total_kg' => $this->total_kg, 'total_liters' => $this->total_liters, 'avg_fat_pct' => $this->avg_fat_pct, 'avg_protein_pct' => $this->avg_protein_pct,
            'base_amount' => $this->base_amount, 'quality_adjustment' => $this->quality_adjustment, 'supplement_amount' => $this->supplement_amount, 'penalty_amount' => $this->penalty_amount,
            'gross_amount' => $this->gross_amount, 'vat_amount' => $this->vat_amount, 'net_amount' => $this->net_amount, 'currency' => $this->currency, 'document_id' => $this->document_id,
            'lines' => array_values(array_map(static fn (array $l): array => [
                'component' => $l['component'], 'label_key' => $l['label_key'], 'quantity_kg' => $l['quantity_kg'], 'rate' => $l['rate'], 'amount' => number_format((float) $l['amount'], 2, '.', ''),
            ], (array) $this->lines)),
        ];
    }
}
