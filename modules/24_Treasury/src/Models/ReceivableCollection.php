<?php

declare(strict_types=1);

namespace Modules\Treasury\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;

final class ReceivableCollection extends BaseEnterpriseModel
{
    protected $table = 'receivable_collections';

    protected function casts(): array
    {
        return [
            'expected_amount' => 'decimal:2',
            'collected_amount' => 'decimal:2',
            'due_date' => 'date',
            'collection_date' => 'date',
            'dunning_level' => 'integer',
        ];
    }
}
