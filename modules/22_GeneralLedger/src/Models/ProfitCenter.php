<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class ProfitCenter extends BaseEnterpriseModel
{
    protected $table = 'profit_centers';

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
