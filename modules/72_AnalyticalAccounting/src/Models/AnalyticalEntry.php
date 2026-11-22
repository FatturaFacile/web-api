<?php

declare(strict_types=1);

namespace Modules\AnalyticalAccounting\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Record of the table analytical_entries.
 */
final class AnalyticalEntry extends BaseEnterpriseModel
{
    protected $table = 'analytical_entries';

    protected function casts(): array
    {
        return ['posting_date' => 'date', 'is_reversed' => 'boolean'];
    }

    /**
     * @return HasMany<AnalyticalEntryLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(AnalyticalEntryLine::class, 'analytical_entry_id')->orderBy('line_number');
    }
}
