<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A journal template posted again at every due date (rent, leasing, fixed monthly charges). The lines are stored as
 * decimal strings; each run posts a new entry through the posting service.
 *
 * @property array<int, array{account_id: string, debit_amount: string, credit_amount: string, description?: ?string, cost_center_id?: ?string, profit_center_id?: ?string}> $lines
 */
final class RecurringJournal extends BaseEnterpriseModel
{
    /**
     * @var list<string>
     */
    public const array Frequencies = ['monthly', 'quarterly', 'semiannual', 'yearly'];

    protected $table = 'gl_recurring_journals';

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'next_run_date' => 'date',
            'last_run_date' => 'date',
            'reverse_next_day' => 'boolean',
            'lines' => 'array',
        ];
    }

    /**
     * @return HasMany<RecurringJournalRun, $this>
     */
    public function runs(): HasMany
    {
        return $this->hasMany(RecurringJournalRun::class, 'recurring_journal_id')->orderBy('run_date');
    }

    /**
     * Months between two runs.
     */
    public function monthsBetweenRuns(): int
    {
        return match ($this->frequency) {
            'monthly' => 1,
            'quarterly' => 3,
            'semiannual' => 6,
            default => 12,
        };
    }
}
