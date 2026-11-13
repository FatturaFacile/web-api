<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class ProfitCenter extends BaseEnterpriseModel
{
    protected $table = 'profit_centers';

    /**
     * Calculated grid columns as portable SQL, so they filter, sort, group and list their values in the database.
     *
     * @return array<string, string>
     */
    public function gridColumns(): array
    {
        return [
            // Booleans are cast to different texts by SQLite and PostgreSQL: a text state is the same on both.
            'activity_state' => "CASE WHEN profit_centers.is_active THEN 'active' ELSE 'inactive' END",
        ];
    }

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function journalLines(): HasMany
    {
        return $this->hasMany(JournalLine::class, 'profit_center_id');
    }
}
