<?php

declare(strict_types=1);

namespace Modules\DownPayments\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * The analytical ledger of a chain: one link per advance invoice (TD02), pro-forma request, deposit, credit note (TD04) or
 * final settlement invoice, with the VAT-rate breakdown of what it states.
 *
 * @property string $chain_id
 * @property string $source_type
 * @property string $source_id
 * @property string|null $installment_id
 * @property string $link_type
 * @property string|null $document_id
 * @property string|null $document_number
 * @property Carbon|null $document_date
 * @property string|null $parent_link_id
 * @property string $status
 * @property string $net_amount
 * @property string $vat_amount
 * @property string $gross_amount
 * @property string $credited_gross_amount
 * @property string $settled_gross_amount
 */
final class DownPaymentLink extends BaseEnterpriseModel
{
    protected $table = 'down_payment_links';

    protected function casts(): array
    {
        return [
            'document_date' => 'date:Y-m-d',
            'net_amount' => 'decimal:2',
            'vat_amount' => 'decimal:2',
            'gross_amount' => 'decimal:2',
            'credited_gross_amount' => 'decimal:2',
            'settled_gross_amount' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<DownPaymentChain, $this>
     */
    public function chain(): BelongsTo
    {
        return $this->belongsTo(DownPaymentChain::class, 'chain_id');
    }

    /**
     * @return HasMany<DownPaymentLinkVatLine, $this>
     */
    public function vatLines(): HasMany
    {
        return $this->hasMany(DownPaymentLinkVatLine::class, 'down_payment_link_id')->orderBy('vat_percentage')->orderBy('id');
    }

    /** Gross still deductible from the final invoice: invoiced less credited and already deducted. */
    public function residualGross(): string
    {
        $residual = bcsub(bcsub((string) $this->gross_amount, (string) $this->credited_gross_amount, 2), (string) $this->settled_gross_amount, 2);

        return bccomp($residual, '0', 2) > 0 ? $residual : '0.00';
    }
}
