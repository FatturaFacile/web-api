<?php

declare(strict_types=1);

namespace Modules\MarketingAutomation\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\MarketingAutomation\Models\StepLog;

/**
 * @mixin StepLog
 */
final class StepLogResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'enrollment_id' => $this->enrollment_id,
            'step_key' => $this->step_key,
            'type' => $this->type,
            'outcome' => $this->outcome,
            'detail' => $this->detail,
            'executed_at' => $this->executed_at?->toIso8601String(),
        ];
    }
}
