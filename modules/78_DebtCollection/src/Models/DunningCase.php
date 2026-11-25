<?php

declare(strict_types=1);

namespace Modules\DebtCollection\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Support\Carbon;

/**
 * The dunning progression of one open receivable item.
 *
 * @property string $customer_id
 * @property string $receivable_item_id
 * @property string $jurisdiction
 * @property string $currency
 * @property int|null $current_level
 * @property Carbon|null $last_notice_on
 * @property Carbon|null $deadline_on
 * @property string $status
 */
final class DunningCase extends BaseEnterpriseModel
{
    protected $table = 'dc_cases';

    protected function casts(): array
    {
        return ['current_level' => 'integer', 'last_notice_on' => 'date', 'deadline_on' => 'date'];
    }
}
