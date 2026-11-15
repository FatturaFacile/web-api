<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Models;

use App\Models\Contact;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\ServiceManagement\Enums\EstimateApprovalStatus;
use Modules\ServiceManagement\Enums\RmaStatus;
use Modules\ServiceManagement\Enums\WarrantyStatus;

/**
 * Depot repair Return Merchandise Authorization (RMA) aggregate.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string|null $company_id
 * @property string $rma_number
 * @property string $equipment_passport_id
 * @property string $customer_id
 * @property string $status
 * @property string $warranty_status
 * @property \Illuminate\Support\Carbon|null $warranty_evaluated_at
 * @property array<string, mixed>|null $warranty_basis
 * @property string $reported_symptom
 * @property \Illuminate\Support\Carbon|null $received_at
 * @property string|null $receipt_condition_notes
 * @property list<array<string, mixed>>|null $receipt_photos
 * @property string|null $lab_bench_diagnostic_notes
 * @property \Illuminate\Support\Carbon|null $diagnosed_at
 * @property string|null $diagnosed_by_user_id
 * @property bool $parts_required
 * @property string|null $repair_estimate_quote_id
 * @property string $estimate_labour_total
 * @property string $estimate_parts_total
 * @property string $estimate_total
 * @property string $currency
 * @property string $preauthorized_threshold
 * @property string $estimate_approval_status
 * @property string|null $estimate_token_hash
 * @property \Illuminate\Support\Carbon|null $estimate_token_expires_at
 * @property \Illuminate\Support\Carbon|null $estimate_decided_at
 * @property string|null $estimate_decided_via
 * @property string|null $estimate_decided_by_name
 * @property string|null $estimate_decision_ip
 * @property string|null $estimate_decision_note
 * @property string|null $tracking_token_hash
 * @property string|null $loaner_id
 * @property string|null $ddt_causale_outbound
 * @property string|null $ddt_causale_return
 * @property bool $is_non_ownership_transfer
 * @property bool $vat_exempt_art15_dpr633
 * @property string|null $repair_outcome
 * @property string|null $repair_test_notes
 * @property \Illuminate\Support\Carbon|null $returned_at
 * @property \Illuminate\Support\Carbon|null $closed_at
 * @property \Illuminate\Support\Carbon|null $cancelled_at
 * @property string|null $cancellation_reason
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
final class DepotRepairRmaOrder extends BaseEnterpriseModel
{
    protected $table = 'esm_rma_orders';

    /**
     * Never serialize token hashes, even by accident.
     *
     * @var list<string>
     */
    protected $hidden = ['estimate_token_hash', 'tracking_token_hash'];

    protected function casts(): array
    {
        return [
            'warranty_evaluated_at' => 'datetime',
            'warranty_basis' => 'array',
            'received_at' => 'datetime',
            'receipt_photos' => 'array',
            'diagnosed_at' => 'datetime',
            'parts_required' => 'boolean',
            'estimate_labour_total' => 'decimal:2',
            'estimate_parts_total' => 'decimal:2',
            'estimate_total' => 'decimal:2',
            'preauthorized_threshold' => 'decimal:2',
            'estimate_token_expires_at' => 'datetime',
            'estimate_decided_at' => 'datetime',
            'is_non_ownership_transfer' => 'boolean',
            'vat_exempt_art15_dpr633' => 'boolean',
            'returned_at' => 'datetime',
            'closed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function statusEnum(): RmaStatus
    {
        return RmaStatus::from($this->status);
    }

    public function warrantyStatusEnum(): WarrantyStatus
    {
        return WarrantyStatus::from($this->warranty_status);
    }

    public function estimateApprovalStatusEnum(): EstimateApprovalStatus
    {
        return EstimateApprovalStatus::from($this->estimate_approval_status);
    }

    /**
     * @return BelongsTo<SerializedEquipmentPassport, $this>
     */
    public function passport(): BelongsTo
    {
        return $this->belongsTo(SerializedEquipmentPassport::class, 'equipment_passport_id');
    }

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'customer_id');
    }

    /**
     * @return HasMany<RmaLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(RmaLine::class, 'rma_order_id');
    }

    /**
     * @return HasMany<RmaStatusHistory, $this>
     */
    public function history(): HasMany
    {
        return $this->hasMany(RmaStatusHistory::class, 'rma_order_id')->orderBy('created_at');
    }

    /**
     * @return HasMany<VendorWarrantyClaim, $this>
     */
    public function vendorClaims(): HasMany
    {
        return $this->hasMany(VendorWarrantyClaim::class, 'rma_order_id');
    }

    /**
     * @return HasMany<Loaner, $this>
     */
    public function loaners(): HasMany
    {
        return $this->hasMany(Loaner::class, 'rma_order_id');
    }

    /**
     * Current "prestito d'uso" loaner unit lent to the customer, if any.
     *
     * @return BelongsTo<Loaner, $this>
     */
    public function loaner(): BelongsTo
    {
        return $this->belongsTo(Loaner::class, 'loaner_id');
    }
}
