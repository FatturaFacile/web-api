<?php

declare(strict_types=1);

namespace Modules\CreditManagement\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;

/**
 * A rating band: the score range that maps to a rating, its risk level and the rule that turns a customer in the band
 * into a credit limit proposal (a share of the quarterly turnover, capped by the ceiling).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $code
 * @property string $name
 * @property string $min_score
 * @property string $max_score
 * @property string $risk_level
 * @property string $limit_multiplier
 * @property string $limit_ceiling
 */
final class CreditRatingBand extends BaseEnterpriseModel
{
    /** @var list<string> */
    public const array RiskLevels = ['low', 'medium', 'high', 'critical'];

    protected $table = 'credit_rating_bands';

    protected function casts(): array
    {
        return [
            'min_score' => 'decimal:2',
            'max_score' => 'decimal:2',
            'limit_multiplier' => 'decimal:2',
            'limit_ceiling' => 'decimal:2',
        ];
    }
}
