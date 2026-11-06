<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PassiveInvoiceLine extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = [
        'tenant_id', 'passive_invoice_id', 'line_number', 'description', 'quantity', 'unit_of_measure', 'unit_price',
        'line_total', 'tax_rate', 'vat_nature', 'cost_account_id', 'cost_account_source',
    ];

    protected function casts(): array
    {
        return [
            'line_number' => 'integer',
            'quantity' => 'decimal:4',
            'unit_price' => 'decimal:8',
            'line_total' => 'decimal:2',
            'tax_rate' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<PassiveInvoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(PassiveInvoice::class, 'passive_invoice_id');
    }
}
