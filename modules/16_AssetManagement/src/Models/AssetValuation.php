<?php

declare(strict_types=1);

namespace Modules\AssetManagement\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Workflow\Contracts\LockableByWorkflowInterface;
use Modules\Workflow\Models\WorkflowInstance;

final class AssetValuation extends BaseEnterpriseModel implements LockableByWorkflowInterface
{
    protected $table = 'eam_asset_valuations';

    protected function casts(): array
    {
        return [
            'valuation_date' => 'date',
            'carrying_amount_before' => 'decimal:2',
            'fair_value' => 'decimal:2',
            'value_in_use' => 'decimal:2',
            'recoverable_amount' => 'decimal:2',
            'adjustment_amount' => 'decimal:2',
            'revaluation_reserve_delta' => 'decimal:2',
            'impairment_loss_amount' => 'decimal:2',
            'carrying_amount_after' => 'decimal:2',
            'is_locked' => 'boolean',
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
