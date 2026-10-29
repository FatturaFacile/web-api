<?php

declare(strict_types=1);

namespace Modules\Inventory\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Workflow\Contracts\LockableByWorkflowInterface;
use Modules\Workflow\Models\WorkflowInstance;

final class Warehouse extends BaseEnterpriseModel implements LockableByWorkflowInterface
{
    protected $table = 'warehouses';

    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
            'is_active' => 'boolean',
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

    public function zones(): HasMany
    {
        return $this->hasMany(WarehouseZone::class, 'warehouse_id');
    }

    public function bins(): HasMany
    {
        return $this->hasMany(WarehouseBin::class, 'warehouse_id');
    }

    public function quants(): HasMany
    {
        return $this->hasMany(StockQuant::class, 'warehouse_id');
    }

    public function reorderRules(): HasMany
    {
        return $this->hasMany(StockReorderRule::class, 'warehouse_id');
    }
}
