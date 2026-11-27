<?php

declare(strict_types=1);

namespace Modules\DownPayments\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The per-VAT-rate analytical line of a link: what the document states, what credit notes took back, what the final invoice
 * deducted and what is still open at that rate.
 *
 * @property string $down_payment_link_id
 * @property string|null $vat_rate_id
 * @property string $vat_code
 * @property string $vat_percentage
 * @property string $net_amount
 * @property string $vat_amount
 * @property string $gross_amount
 * @property string $credited_net_amount
 * @property string $credited_vat_amount
 * @property string $credited_gross_amount
 * @property string $settled_net_amount
 * @property string $settled_vat_amount
 * @property string $settled_gross_amount
 * @property string $residual_net_amount
 * @property string $residual_vat_amount
 * @property string $residual_gross_amount
 */
final class DownPaymentLinkVatLine extends BaseEnterpriseModel
{
    protected $table = 'down_payment_link_vat_lines';

    protected function casts(): array
    {
        return [
            'vat_percentage' => 'decimal:3',
            'net_amount' => 'decimal:2',
            'vat_amount' => 'decimal:2',
            'gross_amount' => 'decimal:2',
            'credited_net_amount' => 'decimal:2',
            'credited_vat_amount' => 'decimal:2',
            'credited_gross_amount' => 'decimal:2',
            'settled_net_amount' => 'decimal:2',
            'settled_vat_amount' => 'decimal:2',
            'settled_gross_amount' => 'decimal:2',
            'residual_net_amount' => 'decimal:2',
            'residual_vat_amount' => 'decimal:2',
            'residual_gross_amount' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<DownPaymentLink, $this>
     */
    public function link(): BelongsTo
    {
        return $this->belongsTo(DownPaymentLink::class, 'down_payment_link_id');
    }

    /** Recomputes the residual columns from the invoiced, credited and settled ones. */
    public function refreshResidual(): void
    {
        foreach (['net', 'vat', 'gross'] as $part) {
            $residual = bcsub(bcsub((string) $this->{$part.'_amount'}, (string) $this->{'credited_'.$part.'_amount'}, 2), (string) $this->{'settled_'.$part.'_amount'}, 2);
            $this->{'residual_'.$part.'_amount'} = bccomp($residual, '0', 2) > 0 ? $residual : '0.00';
        }
    }
}
