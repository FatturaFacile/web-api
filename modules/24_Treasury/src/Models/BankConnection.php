<?php

declare(strict_types=1);

namespace Modules\Treasury\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;

final class BankConnection extends BaseEnterpriseModel
{
    protected $table = 'bank_connections';

    protected function casts(): array
    {
        return [
            'auth_credentials_json' => 'array',
            'last_poll_at' => 'datetime',
        ];
    }
}
