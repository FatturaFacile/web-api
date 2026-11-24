<?php

declare(strict_types=1);

namespace Modules\Inventory\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $landed_cost_document_id
 * @property string $purchase_receipt_id
 */
final class LandedCostDocumentReceipt extends BaseEnterpriseModel
{
    protected $table = 'landed_cost_document_receipts';

    /**
     * @return BelongsTo<LandedCostDocument, $this>
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(LandedCostDocument::class, 'landed_cost_document_id');
    }
}
