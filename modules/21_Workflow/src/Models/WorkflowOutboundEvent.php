<?php

declare(strict_types=1);

namespace Modules\Workflow\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class WorkflowOutboundEvent extends BaseEnterpriseModel
{
    protected $table = 'workflow_outbound_events';

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'event_payload' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function deliveryAttempts(): HasMany
    {
        return $this->hasMany(WorkflowDeliveryAttempt::class, 'outbound_event_id');
    }
}
