<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A withholding (DatiRitenuta) declared on a received supplier invoice, reported in the CU withholding report.
 *
 * @property string $id
 * @property string|null $tenant_id
 * @property string $passive_invoice_id
 * @property string $supplier_id
 * @property string $type
 * @property string|null $reason
 * @property string $rate
 * @property string|null $taxable_amount
 * @property string $amount
 * @property int|null $withheld_year
 */
class PassiveInvoiceWithholding extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = [
        'tenant_id',
        'passive_invoice_id',
        'supplier_id',
        'type',
        'reason',
        'rate',
        'taxable_amount',
        'amount',
        'withheld_year',
    ];

    protected function casts(): array
    {
        return [
            'rate' => 'decimal:2',
            'taxable_amount' => 'decimal:2',
            'amount' => 'decimal:2',
            'withheld_year' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<PassiveInvoice, $this>
     */
    public function passiveInvoice(): BelongsTo
    {
        return $this->belongsTo(PassiveInvoice::class);
    }

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'supplier_id');
    }
}
