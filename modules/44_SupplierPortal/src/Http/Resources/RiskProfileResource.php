<?php

declare(strict_types=1);

namespace Modules\SupplierPortal\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\SupplierPortal\Models\SupplierRiskProfile;

/**
 * The risk flag of a supplier with the reasons behind it.
 *
 * @mixin SupplierRiskProfile
 */
final class RiskProfileResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'supplier_id' => $this->supplier_id,
            'supplier_name' => $this->whenLoaded('supplier', fn () => $this->supplier?->company_name),
            'level' => $this->level,
            'score' => $this->score,
            'reasons' => $this->reasons ?? [],
            'evaluated_at' => $this->evaluated_at?->toIso8601String(),
        ];
    }
}
