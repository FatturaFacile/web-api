<?php

declare(strict_types=1);

namespace App\Http\Resources\Cpq;

use App\Models\QuoteApprovalTier;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin QuoteApprovalTier
 */
class QuoteApprovalTierResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'level' => $this->level,
            'name' => $this->name,
            'approver_role' => $this->approver_role,
            'max_line_discount_percent' => $this->max_line_discount_percent,
            'max_global_discount_percent' => $this->max_global_discount_percent,
            'min_margin_percent' => $this->min_margin_percent,
            'is_active' => $this->is_active,
        ];
    }
}
