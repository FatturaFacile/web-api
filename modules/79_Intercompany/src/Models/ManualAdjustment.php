<?php

declare(strict_types=1);

namespace Modules\Intercompany\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Support\Carbon;

/**
 * A manual consolidation adjustment on a run, kept apart from the run snapshot: a balanced set of lines with a mandatory
 * reference and reason. It is shown in the elimination ledger and applied by the consolidated statements.
 *
 * @property string $amount
 * @property list<array<string, mixed>> $lines
 * @property Carbon|null $created_at
 */
final class ManualAdjustment extends BaseEnterpriseModel
{
    protected $table = 'ic_manual_adjustments';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:4',
            'lines' => 'array',
        ];
    }
}
