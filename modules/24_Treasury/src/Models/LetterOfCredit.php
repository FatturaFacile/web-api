<?php

declare(strict_types=1);

namespace Modules\Treasury\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;

final class LetterOfCredit extends BaseEnterpriseModel
{
    protected $table = 'letters_of_credit';

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'issue_date' => 'date',
            'expiry_date' => 'date',
        ];
    }
}
