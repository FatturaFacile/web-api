<?php

declare(strict_types=1);

namespace Modules\AssetManagement\Models;

use App\Models\User;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Modules\Workflow\Contracts\LockableByWorkflowInterface;
use Modules\Workflow\Models\WorkflowInstance;

/**
 * Eloquent model of the asset transfer records of the asset management module.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string|null $asset_id
 * @property string|null $transfer_number
 * @property string|null $from_location_id
 * @property string|null $to_location_id
 * @property string|null $from_custodian_id
 * @property string|null $to_custodian_id
 * @property string|null $from_project_id
 * @property string|null $to_project_id
 * @property Carbon|null $transfer_date
 * @property string|null $status
 * @property string|null $workflow_instance_id
 * @property bool $is_locked
 * @property string|null $reason
 * @property string|null $initiated_by_user_id
 * @property Carbon|null $completed_at
 * @property array<string, mixed>|null $metadata
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property string|null $from_cost_center_id
 * @property string|null $to_cost_center_id
 */
final class AssetTransfer extends BaseEnterpriseModel implements LockableByWorkflowInterface
{
    protected $table = 'eam_asset_transfers';

    protected function casts(): array
    {
        return [
            'transfer_date' => 'date',
            'completed_at' => 'datetime',
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

    public function fromLocation(): BelongsTo
    {
        return $this->belongsTo(AssetLocation::class, 'from_location_id');
    }

    public function toLocation(): BelongsTo
    {
        return $this->belongsTo(AssetLocation::class, 'to_location_id');
    }

    public function fromCustodian(): BelongsTo
    {
        return $this->belongsTo(User::class, 'from_custodian_id');
    }

    public function toCustodian(): BelongsTo
    {
        return $this->belongsTo(User::class, 'to_custodian_id');
    }
}
