<?php

declare(strict_types=1);

namespace Modules\Invoicing\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A group of sales agents with a common target.
 */
final class SalesTeam extends BaseEnterpriseModel
{
    protected $table = 'sales_teams';

    /**
     * @return HasMany<SalesRepresentative, $this>
     */
    public function representatives(): HasMany
    {
        return $this->hasMany(SalesRepresentative::class, 'sales_team_id');
    }
}
