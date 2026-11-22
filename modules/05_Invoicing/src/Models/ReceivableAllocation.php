<?php

declare(strict_types=1);

namespace Modules\Invoicing\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Settlement of part of an open item by a payment, a credit note, a write-off or a discount. A debit adjustment is
 * stored with a negative amount: it raises the open amount. A reversed allocation stays for the audit trail and no
 * longer counts.
 *
 * @property string $id
 * @property string $receivable_item_id
 * @property string $source_type
 * @property string $origin
 * @property string|null $source_id
 * @property string|null $source_reference
 * @property string $amount
 * @property Carbon $value_date
 * @property Carbon|null $reversed_at
 * @property string|null $notes
 * @property string|null $allocated_by
 */
final class ReceivableAllocation extends BaseEnterpriseModel
{
    public const string TypePayment = 'payment';

    public const string TypeCreditNote = 'credit_note';

    public const string TypeDebitAdjustment = 'debit_adjustment';

    public const string TypeWriteOff = 'write_off';

    public const string TypeDiscount = 'discount';

    public const array Types = [self::TypePayment, self::TypeCreditNote, self::TypeDebitAdjustment, self::TypeWriteOff, self::TypeDiscount];

    public const string OriginManual = 'manual';

    public const string OriginSync = 'sync';

    protected $table = 'receivable_allocations';

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'value_date' => 'date',
            'reversed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<ReceivableItem, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(ReceivableItem::class, 'receivable_item_id');
    }
}
