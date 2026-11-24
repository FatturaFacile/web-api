<?php

declare(strict_types=1);

namespace Modules\SubscriptionBilling\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\SubscriptionBilling\Models\DunningPolicy;

/**
 * The dunning policy of a company: escalation days and collection retries.
 *
 * @mixin DunningPolicy
 */
final class DunningPolicyResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'policy_name' => $this->policy_name,
            'is_default' => (bool) $this->is_default,
            'soft_reminder_days' => (int) $this->soft_reminder_days,
            'formal_notice_days' => (int) $this->formal_notice_days,
            'suspension_days' => (int) $this->suspension_days,
            'termination_days' => (int) $this->termination_days,
            'max_collection_retries' => (int) $this->max_collection_retries,
            'retry_interval_days' => (int) $this->retry_interval_days,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
