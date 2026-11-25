<?php

declare(strict_types=1);

namespace Modules\SalesBoundedContext\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Modules\SalesBoundedContext\Domain\Enums\PortalPaymentProvider;

final class SbcPortalTransaction extends BaseEnterpriseModel
{
    protected $table = 'sbc_portal_transactions';

    protected function casts(): array
    {
        return [
            'payment_provider' => PortalPaymentProvider::class,
            'amount' => 'decimal:2',
            'open_balance_after' => 'decimal:2',
            'settled_at' => 'datetime',
        ];
    }
}
