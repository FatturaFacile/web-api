<?php

declare(strict_types=1);

namespace Modules\Procurement\Models;

use App\Models\User;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Modules\Workflow\Contracts\LockableByWorkflowInterface;
use Modules\Workflow\Models\WorkflowInstance;

/**
 * A supplier contract or framework (blanket) agreement with its committed and consumed amounts and validity.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string|null $company_id
 * @property string $supplier_id
 * @property string $contract_number
 * @property string $title
 * @property string $contract_type
 * @property string $total_committed_amount
 * @property string $consumed_amount
 * @property Carbon $starts_at
 * @property Carbon $ends_at
 * @property string $status
 * @property bool $is_locked
 */
final class SupplierContract extends BaseEnterpriseModel implements LockableByWorkflowInterface
{
    protected $table = 'supplier_contracts';

    protected function casts(): array
    {
        return [
            'total_committed_amount' => 'decimal:2',
            'consumed_amount' => 'decimal:2',
            'starts_at' => 'date',
            'ends_at' => 'date',
            'is_locked' => 'boolean',
        ];
    }

    public function lockByWorkflow(WorkflowInstance $instance): void
    {
        $this->update(['is_locked' => true]);
    }

    public function isLockedByWorkflow(): bool
    {
        return (bool) $this->is_locked;
    }

    /**
     * @return BelongsTo<Supplier, $this>
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'supplier_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return HasMany<SupplierContractLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(SupplierContractLine::class, 'supplier_contract_id');
    }

    /**
     * @return HasMany<PurchaseOrder, $this>
     */
    public function purchaseOrders(): HasMany
    {
        return $this->hasMany(PurchaseOrder::class, 'supplier_contract_id');
    }
}
