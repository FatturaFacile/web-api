<?php

declare(strict_types=1);

namespace Modules\SkiOutdoorSchool\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\SkiOutdoorSchool\Models\PayoutStatement;

/**
 * @mixin PayoutStatement
 */
final class PayoutStatementResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'instructor_id' => $this->instructor_id,
            'instructor_name' => $this->relationLoaded('instructor') ? $this->instructor?->fullName() : null,
            'period_month' => $this->period_month,
            'reference' => $this->reference,
            'hours_total' => $this->hours_total,
            'private_hours' => $this->private_hours,
            'group_hours' => $this->group_hours,
            'gross_total' => $this->gross_total,
            'retention_total' => $this->retention_total,
            'withholding_percent' => $this->withholding_percent,
            'withholding_amount' => $this->withholding_amount,
            'net_payable' => $this->net_payable,
            'regime' => $this->regime->value,
            'status' => $this->status->value,
            'issued_at' => $this->issued_at?->toIso8601String(),
            'commissions' => $this->relationLoaded('commissions') ? CommissionResource::collection($this->commissions) : [],
        ];
    }
}
