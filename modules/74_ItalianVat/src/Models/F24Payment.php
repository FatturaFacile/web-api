<?php

declare(strict_types=1);

namespace Modules\ItalianVat\Models;

use Carbon\CarbonImmutable;
use Enterprise\Foundation\Models\BaseEnterpriseModel;

/**
 * What a withholding F24 owes for a month and tax code, and what was paid.
 *
 * @property int $year
 * @property int $month
 * @property string $tax_code
 * @property string $amount_due
 * @property string $amount_paid
 * @property CarbonImmutable $due_date
 * @property CarbonImmutable|null $paid_at
 * @property string|null $f24_reference
 * @property string|null $withholding_settlement_id
 * @property string $status
 */
final class F24Payment extends BaseEnterpriseModel
{
    protected $table = 'it_f24_payments';

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'month' => 'integer',
            'amount_due' => 'decimal:2',
            'amount_paid' => 'decimal:2',
            'due_date' => 'immutable_date',
            'paid_at' => 'immutable_date',
        ];
    }
}
