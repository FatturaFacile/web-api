<?php

declare(strict_types=1);

namespace Modules\Procurement\Models;

use App\Models\Contact;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Modules\Workflow\Contracts\LockableByWorkflowInterface;
use Modules\Workflow\Models\WorkflowInstance;

/**
 * A supplier of the company. Its id is the id of its contact, so purchase orders and supplier invoices reference the
 * same party; the qualification status decides whether it can receive orders.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string|null $company_id
 * @property string|null $contact_id
 * @property string|null $supplier_category_id
 * @property string $supplier_code
 * @property string $company_name
 * @property string|null $vat_number
 * @property string|null $fiscal_code
 * @property string $currency
 * @property string|null $payment_terms_code
 * @property string|null $incoterms
 * @property string $status
 * @property bool $is_approved_vendor
 * @property string|null $credit_limit
 * @property string|null $rating
 * @property bool $is_locked
 * @property array<string, mixed>|null $metadata
 * @property string $qualification_status
 * @property Carbon|null $qualified_at
 * @property Carbon|null $qualification_review_date
 * @property string|null $over_receipt_tolerance_percent
 * @property string|null $price_tolerance_percent
 */
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
            'qualified_at' => 'date',
            'qualification_review_date' => 'date',
            'over_receipt_tolerance_percent' => 'decimal:2',
            'price_tolerance_percent' => 'decimal:2',
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
     * @return BelongsTo<SupplierCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(SupplierCategory::class, 'supplier_category_id');
    }

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'contact_id');
    }

    /**
     * @return HasMany<SupplierContact, $this>
     */
    public function contacts(): HasMany
    {
        return $this->hasMany(SupplierContact::class, 'supplier_id');
    }

    /**
     * @return HasMany<SupplierCatalog, $this>
     */
    public function catalogs(): HasMany
    {
        return $this->hasMany(SupplierCatalog::class, 'supplier_id');
    }

    /**
     * @return HasMany<SupplierContract, $this>
     */
    public function contracts(): HasMany
    {
        return $this->hasMany(SupplierContract::class, 'supplier_id');
    }

    /**
     * @return HasMany<PurchaseOrder, $this>
     */
    public function purchaseOrders(): HasMany
    {
        return $this->hasMany(PurchaseOrder::class, 'supplier_id');
    }

    /**
     * @return HasMany<SupplierEvaluation, $this>
     */
    public function evaluations(): HasMany
    {
        return $this->hasMany(SupplierEvaluation::class, 'supplier_id');
    }

    /**
     * @return HasMany<SupplierDocument, $this>
     */
    public function documents(): HasMany
    {
        return $this->hasMany(SupplierDocument::class, 'supplier_id');
    }

    /**
     * @return HasMany<SupplierScorecard, $this>
     */
    public function scorecards(): HasMany
    {
        return $this->hasMany(SupplierScorecard::class, 'supplier_id');
    }
}
