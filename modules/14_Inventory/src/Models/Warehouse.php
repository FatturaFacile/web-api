<?php

declare(strict_types=1);

namespace Modules\Inventory\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Logistics\Concerns\HasAuditUsers;
use Modules\Workflow\Contracts\LockableByWorkflowInterface;
use Modules\Workflow\Models\WorkflowInstance;

/**
 * @property string|null $manager_user_id
 * @property bool $is_locked
 * @property string $warehouse_type
 * @property string|null $legal_owner_type
 * @property string|null $linked_partner_id
 * @property string|null $company_entity_id
 */
final class Warehouse extends BaseEnterpriseModel implements LockableByWorkflowInterface
{
    use HasAuditUsers;

    protected $table = 'warehouses';

    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
            'is_active' => 'boolean',
            'is_virtual' => 'boolean',
            'address' => 'array',
        ];
    }

    public function lockByWorkflow(WorkflowInstance $instance): void
    {
        $this->update(['is_active' => false]);
    }

    public function isLockedByWorkflow(): bool
    {
        return ! $this->is_active;
    }

    /**
     * @return HasMany<WarehouseZone, $this>
     */
    public function zones(): HasMany
    {
        return $this->hasMany(WarehouseZone::class, 'warehouse_id');
    }

    /**
     * @return HasMany<StockReorderRule, $this>
     */
    public function reorderRules(): HasMany
    {
        return $this->hasMany(StockReorderRule::class, 'warehouse_id');
    }
}
