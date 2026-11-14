<?php

declare(strict_types=1);

namespace Modules\SupplierPortal\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\SupplierPortal\Models\QualificationCriterion;

/**
 * A line of the qualification checklist.
 *
 * @mixin QualificationCriterion
 */
final class QualificationCriterionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'description' => $this->description,
            'weight' => $this->weight,
            'max_score' => $this->max_score,
            'is_active' => $this->is_active,
            'sort_order' => $this->sort_order,
        ];
    }
}
