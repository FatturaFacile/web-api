<?php

declare(strict_types=1);

namespace App\Domain\Procurement\PurchaseRequisition\Models;

use App\Models\User;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Bidirectional traceability link between a Purchase Requisition line and a downstream Mod 045 RFQ or Mod 046 PO.
 *
 * @property string $id
 * @property string|null $tenant_id
 * @property string $purchase_requisition_id
 * @property string $purchase_requisition_line_id
 * @property string $target_document_type
 * @property string $target_document_id
 * @property string $target_document_number
 * @property string|null $target_document_line_id
 * @property string $converted_quantity
 * @property string|null $created_by_user_id
 * @property string|null $created_by
 * @property string|null $updated_by
 * @property string|null $deleted_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read PurchaseRequisition|null $requisition
 * @property-read PurchaseRequisitionLine|null $requisitionLine
 * @property-read User|null $createdByUser
 */
final class PurchaseRequisitionDocumentLink extends BaseEnterpriseModel
{
    protected $table = 'purchase_requisition_document_links';

    protected function casts(): array
    {
        return [
            'converted_quantity' => 'decimal:4',
        ];
    }

    /**
     * @return BelongsTo<PurchaseRequisition, $this>
     */
    public function requisition(): BelongsTo
    {
        return $this->belongsTo(PurchaseRequisition::class, 'purchase_requisition_id');
    }

    /**
     * @return BelongsTo<PurchaseRequisitionLine, $this>
     */
    public function requisitionLine(): BelongsTo
    {
        return $this->belongsTo(PurchaseRequisitionLine::class, 'purchase_requisition_line_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
