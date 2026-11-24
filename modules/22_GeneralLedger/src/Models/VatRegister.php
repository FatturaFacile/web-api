<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;

final class VatRegister extends BaseEnterpriseModel
{
    protected $table = 'vat_registers';

    protected function casts(): array
    {
        return [
            'progressive_protocol_year' => 'integer',
            'last_protocol_number' => 'integer',
        ];
    }
}
