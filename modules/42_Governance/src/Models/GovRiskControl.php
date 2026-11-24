<?php

declare(strict_types=1);

namespace Modules\Governance\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Link between a risk and a control that mitigates it.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $risk_id
 * @property string $control_id
 */
final class GovRiskControl extends BaseEnterpriseModel
{
    protected $table = 'gov_risk_controls';

    /**
     * @return BelongsTo<GovRisk, $this>
     */
    public function risk(): BelongsTo
    {
        return $this->belongsTo(GovRisk::class, 'risk_id');
    }

    /**
     * @return BelongsTo<GovControl, $this>
     */
    public function control(): BelongsTo
    {
        return $this->belongsTo(GovControl::class, 'control_id');
    }
}
