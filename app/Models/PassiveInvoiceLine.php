<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Database\Factories\PassiveInvoiceLineFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $passive_invoice_id
 * @property string|null $description
 * @property string $line_total
 * @property string|null $cost_account_id
 * @property bool $is_capital_asset
 * @property string|null $asset_category_id
 * @property-read PassiveInvoice|null $invoice
 */
class PassiveInvoiceLine extends Model
{
    /** @use HasFactory<PassiveInvoiceLineFactory> */
    use BelongsToTenant, HasFactory, HasUuids;

    protected $fillable = [
        'tenant_id', 'passive_invoice_id', 'line_number', 'description', 'quantity', 'unit_of_measure', 'unit_price',
        'line_total', 'tax_rate', 'vat_nature', 'cost_account_id', 'cost_account_source',
        'competence_start_date', 'competence_end_date', 'is_capital_asset', 'asset_category_id',
    ];

    protected function casts(): array
    {
        return [
            'line_number' => 'integer',
            'quantity' => 'decimal:4',
            'unit_price' => 'decimal:8',
            'line_total' => 'decimal:2',
            'tax_rate' => 'decimal:2',
            'competence_start_date' => 'date',
            'competence_end_date' => 'date',
            'is_capital_asset' => 'boolean',
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
