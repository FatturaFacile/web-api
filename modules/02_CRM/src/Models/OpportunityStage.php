<?php

declare(strict_types=1);

namespace Modules\CRM\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class OpportunityStage extends BaseEnterpriseModel
{
    protected $table = 'crm_opportunity_stages';

    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'default_probability' => 'decimal:2',
            'is_won' => 'boolean',
            'is_lost' => 'boolean',
            'stale_days' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function opportunities(): HasMany
    {
        return $this->hasMany(Opportunity::class, 'stage_id');
    }
}
