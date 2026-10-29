<?php

declare(strict_types=1);

namespace Modules\CRM\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\CRM\Models\Account;

/**
 * @mixin Account
 */
final class AccountResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'account_number' => $this->account_number,
            'name' => $this->name,
            'industry' => $this->industry,
            'tier' => $this->tier,
            'status' => $this->status,
            'annual_revenue' => $this->annual_revenue,
            'employee_count' => $this->employee_count,
            'website' => $this->website,
            'customer_health_index' => (float) $this->customer_health_index,
            'churn_risk' => $this->churn_risk,
            'lifecycle_stage' => $this->lifecycle_stage,
            'last_activity_at' => $this->last_activity_at?->toIso8601String(),
            'tags' => $this->tags,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
