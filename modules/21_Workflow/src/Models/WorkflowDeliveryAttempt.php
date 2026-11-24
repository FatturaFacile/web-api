<?php

declare(strict_types=1);

namespace Modules\Workflow\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class WorkflowDeliveryAttempt extends BaseEnterpriseModel
{
    protected $table = 'workflow_delivery_attempts';

    protected function casts(): array
    {
        return [
            'attempt_number' => 'integer',
            'http_status' => 'integer',
            'latency_ms' => 'integer',
            'next_retry_at' => 'datetime',
        ];
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(WorkflowWebhookSubscription::class, 'subscription_id');
    }

    public function outboundEvent(): BelongsTo
    {
        return $this->belongsTo(WorkflowOutboundEvent::class, 'outbound_event_id');
    }
}
