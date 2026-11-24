<?php

declare(strict_types=1);

namespace Modules\Inventory\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Support\Carbon;

/**
 * Inventory revaluation (landed cost, valuation method change, manual). Never rewrites
 * historical movements; the GL effect is an adjustment journal (`journal_entry_id`).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string|null $company_id
 * @property string $source_type
 * @property string|null $source_id
 * @property string $status
 * @property Carbon $posting_date
 * @property string $total_inventory_delta
 * @property string $total_cogs_delta
 * @property string $total_variance_delta
 * @property string|null $journal_entry_id
 * @property string|null $reversal_of_id
 * @property array<int, array<string, mixed>>|null $lines
 */
final class InventoryRevaluation extends BaseEnterpriseModel
{
    protected $table = 'inventory_revaluations';

    protected function casts(): array
    {
        return [
            'posting_date' => 'date',
            'total_inventory_delta' => 'decimal:2',
            'total_cogs_delta' => 'decimal:2',
            'total_variance_delta' => 'decimal:2',
            'lines' => 'array',
        ];
    }
}
