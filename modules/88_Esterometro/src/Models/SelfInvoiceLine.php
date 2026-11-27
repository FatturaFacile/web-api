<?php

declare(strict_types=1);

namespace Modules\Esterometro\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Esterometro\Domain\Enums\TransactionNature;

/**
 * Line item of an Italian Esterometro / Reverse Charge self-invoice (TD16–TD28).
 *
 * @property string $id
 * @property string|null $tenant_id
 * @property string $self_invoice_id
 * @property string|null $passive_invoice_line_id
 * @property int $line_number
 * @property string $description
 * @property TransactionNature $transaction_nature
 * @property string $quantity
 * @property string $unit_price_original
 * @property string $unit_price_eur
 * @property string $taxable_amount_original
 * @property string $taxable_amount_eur
 * @property string|null $source_vat_nature
 * @property string $vat_rate
 * @property string|null $vat_nature
 * @property string $vat_amount_eur
 * @property string $deductibility_percent
 * @property string $deductible_vat_eur
 * @property string $non_deductible_vat_eur
 * @property string|null $cost_account_id
 * @property string|null $normative_reference
 */
final class SelfInvoiceLine extends BaseEnterpriseModel
{
    protected $table = 'self_invoice_lines';

    protected $fillable = [
        'tenant_id',
        'self_invoice_id',
        'passive_invoice_line_id',
        'line_number',
        'description',
        'transaction_nature',
        'quantity',
        'unit_price_original',
        'unit_price_eur',
        'taxable_amount_original',
        'taxable_amount_eur',
        'source_vat_nature',
        'vat_rate',
        'vat_nature',
        'vat_amount_eur',
        'deductibility_percent',
        'deductible_vat_eur',
        'non_deductible_vat_eur',
        'cost_account_id',
        'normative_reference',
    ];

    protected function casts(): array
    {
        return [
            'line_number' => 'integer',
            'transaction_nature' => TransactionNature::class,
            'quantity' => 'decimal:4',
            'unit_price_original' => 'decimal:4',
            'unit_price_eur' => 'decimal:4',
            'taxable_amount_original' => 'decimal:2',
            'taxable_amount_eur' => 'decimal:2',
            'vat_rate' => 'decimal:2',
            'vat_amount_eur' => 'decimal:2',
            'deductibility_percent' => 'decimal:2',
            'deductible_vat_eur' => 'decimal:2',
            'non_deductible_vat_eur' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<SelfInvoice, $this>
     */
    public function selfInvoice(): BelongsTo
    {
        return $this->belongsTo(SelfInvoice::class, 'self_invoice_id');
    }
}
