<?php

declare(strict_types=1);

namespace Modules\Controlling\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;

/**
 * Controlling record of the table bi_sales_margin_facts.
 */
final class SalesMarginFact extends BaseEnterpriseModel
{
    protected $table = 'bi_sales_margin_facts';

    protected function casts(): array
    {
        return ['posting_date' => 'date'];
    }
}
