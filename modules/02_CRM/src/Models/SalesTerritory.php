<?php

declare(strict_types=1);

namespace Modules\CRM\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A sales territory: accounts matching its criteria (countries, industries, account tiers) belong to it; the lowest priority number wins when several match. criteria is an object with optional countries, industries and tiers lists.
 *
 * @property array{countries?: list<string>, industries?: list<string>, tiers?: list<string>}|null $criteria
 */
final class SalesTerritory extends BaseEnterpriseModel
{
    protected $table = 'crm_territories';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'criteria' => 'array',
            'priority' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return HasMany<SalesQuota, $this>
     */
    public function quotas(): HasMany
    {
        return $this->hasMany(SalesQuota::class, 'territory_id');
    }

    /**
     * @return HasMany<AccountTerritory, $this>
     */
    public function accountLinks(): HasMany
    {
        return $this->hasMany(AccountTerritory::class, 'territory_id');
    }
}
