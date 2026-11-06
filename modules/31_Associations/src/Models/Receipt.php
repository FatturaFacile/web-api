<?php

declare(strict_types=1);

namespace Modules\Associations\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Associations\Enums\ReceiptKind;

/**
 * A numbered receipt. The holder data is a frozen, encrypted copy: later changes of the registry do not alter it.
 */
final class Receipt extends AssociationRecord
{
    protected $table = 'assoc_receipts';

    protected $hidden = ['holder'];

    protected function casts(): array
    {
        return [
            'kind' => ReceiptKind::class,
            'issued_on' => 'date',
            'amount' => 'decimal:2',
            'holder' => 'encrypted:array',
            'fiscal_year' => 'integer',
            'sequential_number' => 'integer',
        ];
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'member_id');
    }
}
