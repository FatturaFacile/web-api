<?php

declare(strict_types=1);

namespace Modules\AssetManagement\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\AssetManagement\Models\AssetImprovement;

/**
 * @mixin AssetImprovement
 */
final class AssetImprovementResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'asset_id' => $this->asset_id,
            'improvement_number' => $this->improvement_number,
            'improvement_date' => $this->improvement_date->format('Y-m-d'),
            'amount' => (string) $this->amount,
            'description' => $this->description,
            'supplier_id' => $this->supplier_id,
            'purchase_invoice_id' => $this->purchase_invoice_id,
            'purchase_invoice_number' => $this->purchase_invoice_number,
            'extends_useful_life_months' => (int) $this->extends_useful_life_months,
            'affects_tax_book' => (bool) $this->affects_tax_book,
            'status' => $this->status,
            'journal_entry_id' => $this->journal_entry_id,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
