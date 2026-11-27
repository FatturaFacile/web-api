<?php

declare(strict_types=1);

namespace Modules\DownPayments\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One installment of the payment plan of a chain: what to ask the customer for, when, and with which instrument.
 *
 * @property string $chain_id
 * @property int $sequence
 * @property string $milestone_label
 * @property string $trigger_type
 * @property Carbon|null $due_date
 * @property string $calculation_mode
 * @property string|null $percentage
 * @property string $amount_net
 * @property string $amount_vat
 * @property string $amount_gross
 * @property string $instrument_type
 * @property string $status
 * @property list<array{vat_rate: string, net: string, vat: string, gross: string}>|null $vat_breakdown
 * @property string|null $linked_document_id
 * @property string|null $linked_invoice_id
 */
final class DownPaymentInstallment extends BaseEnterpriseModel
{
    protected $table = 'down_payment_schedule_installments';

    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'due_date' => 'date:Y-m-d',
            'percentage' => 'decimal:4',
            'amount_net' => 'decimal:2',
            'amount_vat' => 'decimal:2',
            'amount_gross' => 'decimal:2',
            'vat_breakdown' => 'array',
        ];
    }

    /**
     * @return BelongsTo<DownPaymentChain, $this>
     */
    public function chain(): BelongsTo
    {
        return $this->belongsTo(DownPaymentChain::class, 'chain_id');
    }
}
