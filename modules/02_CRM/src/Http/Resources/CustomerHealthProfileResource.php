<?php

declare(strict_types=1);

namespace Modules\CRM\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\CRM\Models\CustomerHealthProfile;

/**
 * @mixin CustomerHealthProfile
 */
final class CustomerHealthProfileResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'account_id' => $this->account_id,
            'activity_score' => (float) $this->activity_score,
            'engagement_score' => (float) $this->engagement_score,
            'revenue_score' => (float) $this->revenue_score,
            'risk_score' => (float) $this->risk_score,
            'churn_risk_indicator' => $this->churn_risk_indicator,
            'health_index' => (float) $this->health_index,
            'last_interaction_at' => $this->last_interaction_at?->toIso8601String(),
            'open_receivable_amount' => (float) $this->open_receivable_amount,
            'overdue_receivable_amount' => (float) $this->overdue_receivable_amount,
            'evaluation_metrics' => $this->evaluation_metrics,
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
