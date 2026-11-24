<?php

declare(strict_types=1);

namespace Modules\CRM\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\CRM\Models\Forecast;

/**
 * @mixin Forecast
 */
final class ForecastResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'period' => $this->period,
            'user_id' => $this->user_id,
            'currency' => $this->currency,
            'pipeline_amount' => (float) $this->pipeline_amount,
            'best_case_amount' => (float) $this->best_case_amount,
            'commit_amount' => (float) $this->commit_amount,
            'closed_amount' => (float) $this->closed_amount,
            'weighted_amount' => (float) $this->weighted_amount,
            'quota_amount' => (float) $this->quota_amount,
            'attainment_percent' => (float) $this->attainment_percent,
            'status' => $this->status,
            'workflow_instance_id' => $this->workflow_instance_id,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
