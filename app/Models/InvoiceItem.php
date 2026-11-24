<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\ProtectsIssuedInvoiceItem;
use Enterprise\MultiTenant\Concerns\StampsTenantFromParent;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property array<string, mixed>|null $sdi_data
 * @property Carbon|null $competence_start_date
 * @property Carbon|null $competence_end_date
 */
class InvoiceItem extends Model
{
    use HasFactory, HasUuids, ProtectsIssuedInvoiceItem, StampsTenantFromParent;

    protected $fillable = [
        'invoice_id',
        'product_id',
        'description',
        'quantity',
        'unit_price',
        'tax_rate',
        'discount_percentage',
        'line_total',
        'revenue_account_id',
        'product_charge_type_id',
        'taric_code',
        'country_of_origin',
        'origin_type',
        'made_in_label',
        'natura',
        'withholding_applies',
        'fund_applies',
        'sdi_data',
        'competence_start_date',
        'competence_end_date',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'unit_price' => 'decimal:4',
            'tax_rate' => 'decimal:2',
            'discount_percentage' => 'decimal:2',
            'line_total' => 'decimal:2',
            'withholding_applies' => 'boolean',
            'fund_applies' => 'boolean',
            'sdi_data' => 'array',
            'competence_start_date' => 'date',
            'competence_end_date' => 'date',
        ];
    }

    /**
     * @return BelongsTo<Invoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
