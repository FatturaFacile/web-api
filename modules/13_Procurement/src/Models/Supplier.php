<?php

declare(strict_types=1);

namespace Modules\Procurement\Models;

use App\Models\Contact;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Workflow\Contracts\LockableByWorkflowInterface;
use Modules\Workflow\Models\WorkflowInstance;

final class Supplier extends BaseEnterpriseModel implements LockableByWorkflowInterface
{
    protected $table = 'suppliers';

    protected function casts(): array
    {
        return [
            'is_approved_vendor' => 'boolean',
            'credit_limit' => 'decimal:2',
            'is_locked' => 'boolean',
            'metadata' => 'array',
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

    public function category(): BelongsTo
    {
        return $this->belongsTo(SupplierCategory::class, 'supplier_category_id');
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'contact_id');
    }

    public function contacts(): HasMany
    {
        return $this->hasMany(SupplierContact::class, 'supplier_id');
    }

    public function catalogs(): HasMany
    {
        return $this->hasMany(SupplierCatalog::class, 'supplier_id');
    }

    public function contracts(): HasMany
    {
        return $this->hasMany(SupplierContract::class, 'supplier_id');
    }

    public function purchaseOrders(): HasMany
    {
        return $this->hasMany(PurchaseOrder::class, 'supplier_id');
    }

    public function evaluations(): HasMany
    {
        return $this->hasMany(SupplierEvaluation::class, 'supplier_id');
    }

    public function scorecards(): HasMany
    {
        return $this->hasMany(SupplierScorecard::class, 'supplier_id');
    }
}
