<?php

declare(strict_types=1);

namespace Modules\ForeignVatRefund\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Modules\ForeignVatRefund\Domain\Enums\LedgerStatus;
use Modules\ForeignVatRefund\Domain\Enums\SourceType;

/**
 * A line of foreign VAT paid on a supplier document, split into the recoverable part (parked on the transitory receivable account
 * until refunded) and the non recoverable part (an expense).
 *
 * @property SourceType $source_type
 * @property string|null $source_id
 * @property string $document_number
 * @property Carbon $document_date
 * @property string|null $supplier_name
 * @property string|null $supplier_vat_number
 * @property string $supplier_country_code
 * @property int $expense_category_code
 * @property string $net_amount
 * @property string $foreign_vat_rate
 * @property string $foreign_vat_amount
 * @property string $currency_code
 * @property string $exchange_rate_to_base
 * @property string $base_currency_foreign_vat_amount
 * @property string $eligible_percentage
 * @property string $recoverable_vat_amount
 * @property string $non_recoverable_vat_amount
 * @property string $base_recoverable_vat_amount
 * @property string|null $transitory_account_id
 * @property string|null $journal_entry_id
 * @property LedgerStatus $status
 * @property string|null $vat_refund_claim_id
 * @property int $fiscal_year
 * @property int $fiscal_quarter
 * @property string|null $attachment_path
 * @property string|null $posting_error
 */
final class ForeignVatLedgerEntry extends BaseEnterpriseModel
{
    protected $table = 'foreign_vat_ledger';

    protected function casts(): array
    {
        return [
            'source_type' => SourceType::class,
            'document_date' => 'date',
            'expense_category_code' => 'integer',
            'net_amount' => 'decimal:2',
            'foreign_vat_rate' => 'decimal:4',
            'foreign_vat_amount' => 'decimal:2',
            'exchange_rate_to_base' => 'decimal:6',
            'base_currency_foreign_vat_amount' => 'decimal:2',
            'eligible_percentage' => 'decimal:2',
            'recoverable_vat_amount' => 'decimal:2',
            'non_recoverable_vat_amount' => 'decimal:2',
            'base_recoverable_vat_amount' => 'decimal:2',
            'status' => LedgerStatus::class,
            'fiscal_year' => 'integer',
            'fiscal_quarter' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<VatRefundClaim, $this>
     */
    public function claim(): BelongsTo
    {
        return $this->belongsTo(VatRefundClaim::class, 'vat_refund_claim_id');
    }
}
