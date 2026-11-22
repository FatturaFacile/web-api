<?php

declare(strict_types=1);

namespace Modules\Invoicing\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Support\Carbon;

/**
 * Log of a customer statement sent by email.
 *
 * @property string $id
 * @property string $customer_id
 * @property Carbon $period_from
 * @property Carbon $period_to
 * @property string $recipient
 * @property string $locale
 * @property string $status
 */
final class CustomerStatementDispatch extends BaseEnterpriseModel
{
    protected $table = 'customer_statement_dispatches';

    protected function casts(): array
    {
        return [
            'period_from' => 'date',
            'period_to' => 'date',
            'sent_at' => 'datetime',
        ];
    }
}
