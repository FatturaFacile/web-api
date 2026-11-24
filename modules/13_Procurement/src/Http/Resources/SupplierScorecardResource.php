<?php

declare(strict_types=1);

namespace Modules\Procurement\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class SupplierScorecardResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'supplier_id' => $this->supplier_id,
            'year' => $this->year,
            'quarter' => $this->quarter,
            'otif_percentage' => (float) $this->otif_percentage,
            'defect_ppm' => $this->defect_ppm,
            'cost_savings_amount' => (float) $this->cost_savings_amount,
            'compliance_score' => (float) $this->compliance_score,
            'risk_level' => $this->risk_level,
            'is_avl_active' => (bool) $this->is_avl_active,
            'scorecard_data' => $this->scorecard_data,
        ];
    }
}
