<?php

declare(strict_types=1);

namespace Modules\AgentCommissions\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Cumulative figures of an agent for a calendar year: the commission settled, the Enasarco base against the yearly
 * ceiling, the contributions of both shares and the FIRR accrued.
 *
 * @property string $agent_profile_id
 * @property int $year
 * @property string $commission_ytd
 * @property string $enasarco_base_ytd
 * @property string $enasarco_agent_ytd
 * @property string $enasarco_company_ytd
 * @property string $firr_ytd
 * @property string|null $massimale
 * @property string|null $minimale
 */
final class AgentYearBalance extends BaseEnterpriseModel
{
    protected $table = 'agc_agent_year_balances';

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'commission_ytd' => 'decimal:2',
            'enasarco_base_ytd' => 'decimal:2',
            'enasarco_agent_ytd' => 'decimal:2',
            'enasarco_company_ytd' => 'decimal:2',
            'firr_ytd' => 'decimal:2',
            'massimale' => 'decimal:2',
            'minimale' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<AgentProfile, $this>
     */
    public function agent(): BelongsTo
    {
        return $this->belongsTo(AgentProfile::class, 'agent_profile_id');
    }
}
