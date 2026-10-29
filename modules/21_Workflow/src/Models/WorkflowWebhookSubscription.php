<?php

declare(strict_types=1);

namespace Modules\Workflow\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class WorkflowWebhookSubscription extends BaseEnterpriseModel
{
    protected $table = 'workflow_webhook_subscriptions';

    protected function casts(): array
    {
        return [
            'subscribed_events' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function deliveryAttempts(): HasMany
    {
        return $this->hasMany(WorkflowDeliveryAttempt::class, 'subscription_id');
    }
}
