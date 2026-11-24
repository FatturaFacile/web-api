<?php

declare(strict_types=1);

namespace Modules\AssetManagement\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Modules\Workflow\Contracts\LockableByWorkflowInterface;
use Modules\Workflow\Models\WorkflowInstance;

/**
 * Eloquent model of the asset valuation records of the asset management module.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string|null $asset_id
 * @property string|null $valuation_number
 * @property string|null $valuation_type
 * @property Carbon|null $valuation_date
 * @property string|null $carrying_amount_before
 * @property string|null $fair_value
 * @property string|null $value_in_use
 * @property string|null $recoverable_amount
 * @property string|null $adjustment_amount
 * @property string|null $revaluation_reserve_delta
 * @property string|null $impairment_loss_amount
 * @property string|null $carrying_amount_after
 * @property string|null $status
 * @property string|null $workflow_instance_id
 * @property string|null $journal_entry_id
 * @property bool $is_locked
 * @property string|null $appraiser_name
 * @property string|null $reason
 * @property string|null $created_by_user_id
 * @property array<string, mixed>|null $metadata
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
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
