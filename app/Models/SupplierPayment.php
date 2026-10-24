<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A payment made to a supplier against a received invoice. Bank fees and rounding adjustments are recorded as separate
 * entries (`type`), like the payments of customer invoices.
 */
class SupplierPayment extends Model
{
    use BelongsToTenant, HasFactory, HasUuids;

    protected $fillable = [
        'tenant_id',
        'passive_invoice_id',
        'amount',
        'paid_at',
        'type',
        'reference',
        'notes',
        'bank_statement_line_id',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'paid_at' => 'date',
        ];
    }

    /**
     * @return BelongsTo<PassiveInvoice, $this>
     */
    public function passiveInvoice(): BelongsTo
    {
        return $this->belongsTo(PassiveInvoice::class);
    }
}
