<?php

declare(strict_types=1);

namespace Modules\Treasury\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;

final class OpenBankingPayment extends BaseEnterpriseModel
{
    protected $table = 'open_banking_payments';

    protected function casts(): array
    {
        return ['amount' => 'decimal:2'];
    }
}
