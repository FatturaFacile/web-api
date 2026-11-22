<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An escalation chain (#43): ordered steps fired when the elapsed share of an SLA target crosses their threshold. An SLA
 * policy points to a chain.
 *
 * @property string $code
 * @property string $name
 * @property bool $is_active
 */
final class EscalationChain extends BaseEnterpriseModel
{
    protected $table = 'esm_escalation_chains';

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return HasMany<EscalationStep, $this>
     */
    public function steps(): HasMany
    {
        return $this->hasMany(EscalationStep::class, 'chain_id')->orderBy('threshold_percent')->orderBy('position');
    }
}
