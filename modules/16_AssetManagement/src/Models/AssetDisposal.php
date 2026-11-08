<?php

declare(strict_types=1);

namespace Modules\AssetManagement\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Modules\Workflow\Contracts\LockableByWorkflowInterface;
use Modules\Workflow\Models\WorkflowInstance;

/**
 * Eloquent model of the asset disposal records of the asset management module.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string|null $asset_id
 * @property string|null $disposal_number
 * @property string|null $disposal_type
 * @property Carbon|null $disposal_date
 * @property string|null $asset_cost_at_disposal
 * @property string|null $accumulated_depreciation_at_disposal
 * @property string|null $carrying_amount_at_disposal
 * @property string|null $proceeds_amount
 * @property string|null $disposal_costs
 * @property string|null $gain_loss_amount
 * @property string|null $buyer_contact_id
 * @property string|null $status
 * @property string|null $workflow_instance_id
 * @property string|null $journal_entry_id
 * @property bool $is_locked
 * @property string|null $reason
 * @property string|null $initiated_by_user_id
 * @property Carbon|null $completed_at
 * @property array<string, mixed>|null $metadata
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property string|null $disposed_percentage
 * @property bool $is_partial
 */
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
            'disposed_percentage' => 'decimal:4',
            'is_partial' => 'boolean',
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
