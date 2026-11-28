<?php

declare(strict_types=1);

namespace Modules\SkiOutdoorSchool\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\SkiOutdoorSchool\Models\InstructorCommission;

/**
 * @mixin InstructorCommission
 */
final class CommissionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'instructor_id' => $this->instructor_id,
            'source_type' => $this->source_type->value,
            'source_id' => $this->source_id,
            'work_date' => $this->work_date->toDateString(),
            'hours' => $this->hours,
            'gross_fee' => $this->gross_fee,
            'retention_mode' => $this->retention_mode->value,
            'retention_value' => $this->retention_value,
            'retention_amount' => $this->retention_amount,
            'net_fee' => $this->net_fee,
            'payout_statement_id' => $this->payout_statement_id,
        ];
    }
}
