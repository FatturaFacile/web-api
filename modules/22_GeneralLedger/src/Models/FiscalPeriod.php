<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Enterprise\MultiTenant\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class FiscalPeriod extends BaseEnterpriseModel
{
    use BelongsToCompany;

    protected $table = 'fiscal_periods';

    protected function casts(): array
    {
        return [
            'period_number' => 'integer',
            'start_date' => 'date',
            'end_date' => 'date',
            'fiduciary_locked_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<FiscalYear, $this>
     */
    public function fiscalYear(): BelongsTo
    {
        return $this->belongsTo(FiscalYear::class, 'fiscal_year_id');
    }

    public function locks(): HasMany
    {
        return $this->hasMany(PeriodLock::class, 'fiscal_period_id');
    }

    public function journalEntries(): HasMany
    {
        return $this->hasMany(JournalEntry::class, 'fiscal_period_id');
    }

    public function isFiduciaryLocked(): bool
    {
        return $this->status === 'locked_by_fiduciary' || $this->fiduciary_locked_at !== null;
    }

    public function isLockedFor(string $subledger = 'GL'): bool
    {
        if (in_array($this->status, ['hard_locked', 'closed', 'locked_by_fiduciary'], true) || $this->fiduciary_locked_at !== null) {
            return true;
        }

        return $this->locks()
            ->where('subledger_module', strtoupper($subledger))
            ->where('is_locked', true)
            ->exists();
    }
}
