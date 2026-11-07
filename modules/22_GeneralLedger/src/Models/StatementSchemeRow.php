<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;

final class StatementSchemeRow extends BaseEnterpriseModel
{
    protected $table = 'gl_statement_scheme_rows';

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'sign' => 'integer',
            'account_prefixes' => 'array',
            'formula' => 'array',
        ];
    }
}
