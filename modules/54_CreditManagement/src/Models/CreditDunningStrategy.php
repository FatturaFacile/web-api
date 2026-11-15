<?php

declare(strict_types=1);

namespace Modules\CreditManagement\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A dunning strategy: the ordered steps a collector follows on an overdue customer.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $name
 * @property bool $is_default
 * @property bool $is_active
 * @property string|null $description
 */
final class CreditDunningStrategy extends BaseEnterpriseModel
{
    protected $table = 'credit_dunning_strategies';

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return HasMany<CreditDunningStep, $this>
     */
    public function steps(): HasMany
    {
        return $this->hasMany(CreditDunningStep::class, 'strategy_id')->orderBy('step_order');
    }
}
