<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Support\Carbon;

/**
 * A completed stage of the year-end closing workflow of one fiscal year and ledger book.
 *
 * @property string $fiscal_year_id
 * @property string $ledger_type
 * @property int $stage
 * @property string $stage_key
 * @property string|null $journal_entry_id
 * @property array<string, mixed>|null $summary
 * @property string|null $executed_by
 * @property Carbon $executed_at
 */
final class YearEndClosingStage extends BaseEnterpriseModel
{
    protected $table = 'year_end_closing_stages';

    protected function casts(): array
    {
        return [
            'stage' => 'integer',
            'summary' => 'array',
            'executed_at' => 'datetime',
        ];
    }
}
