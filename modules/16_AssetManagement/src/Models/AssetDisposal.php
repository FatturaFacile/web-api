<?php

declare(strict_types=1);

namespace Modules\AssetManagement\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Workflow\Contracts\LockableByWorkflowInterface;
use Modules\Workflow\Models\WorkflowInstance;

final class AssetDisposal extends BaseEnterpriseModel implements LockableByWorkflowInterface
{
    protected $table = 'eam_asset_disposals';

    protected function casts(): array
    {
        return [
            'disposal_date' => 'date',
            'asset_cost_at_disposal' => 'decimal:2',
            'accumulated_depreciation_at_disposal' => 'decimal:2',
            'carrying_amount_at_disposal' => 'decimal:2',
            'proceeds_amount' => 'decimal:2',
            'disposal_costs' => 'decimal:2',
            'gain_loss_amount' => 'decimal:2',
            'is_locked' => 'boolean',
            'completed_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function lockByWorkflow(WorkflowInstance $instance): void
    {
        $this->update([
            'workflow_instance_id' => $instance->id,
            'status' => 'pending_approval',
            'is_locked' => true,
        ]);
    }

    public function isLockedByWorkflow(): bool
    {
        return (bool) $this->is_locked;
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(FixedAsset::class, 'asset_id');
    }
}
