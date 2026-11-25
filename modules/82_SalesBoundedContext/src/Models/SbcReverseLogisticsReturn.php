<?php

declare(strict_types=1);

namespace Modules\SalesBoundedContext\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\SalesBoundedContext\Domain\Enums\BStockConditionGrade;

/**
 * @property string $id
 * @property string|null $tenant_id
 * @property string|null $dossier_id
 * @property string|null $original_invoice_id
 * @property string|null $credit_note_document_id
 * @property string $rma_number
 * @property string $return_reason
 * @property bool $is_advance_cross_shipping
 * @property string|null $replacement_shipment_ref
 * @property BStockConditionGrade|null $b_stock_grade
 * @property string $inventory_disposition
 * @property int $days_since_invoice
 * @property bool $art26_dpr633_72_vat_recoverable
 * @property string|null $art26_compliance_note
 * @property string $return_net_amount
 * @property string $return_vat_amount
 * @property string $credit_note_gross_amount
 * @property string $currency
 * @property string $status
 */
final class SbcReverseLogisticsReturn extends BaseEnterpriseModel
{
    protected $table = 'sbc_reverse_logistics_returns';

    protected function casts(): array
    {
        return [
            'is_advance_cross_shipping' => 'boolean',
            'b_stock_grade' => BStockConditionGrade::class,
            'original_invoice_date' => 'date',
            'days_since_invoice' => 'integer',
            'art26_dpr633_72_vat_recoverable' => 'boolean',
            'return_net_amount' => 'decimal:2',
            'return_vat_amount' => 'decimal:2',
            'credit_note_gross_amount' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<SbcCustomerDossier, $this>
     */
    public function dossier(): BelongsTo
    {
        return $this->belongsTo(SbcCustomerDossier::class, 'dossier_id');
    }

    /**
     * @return BelongsTo<SbcDdtInvoice, $this>
     */
    public function creditNoteDocument(): BelongsTo
    {
        return $this->belongsTo(SbcDdtInvoice::class, 'credit_note_document_id');
    }
}
