<?php

declare(strict_types=1);

namespace Modules\CreditManagement\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;

/**
 * One factor of the credit scorecard: a source of a 0-100 sub-score and the weight it carries in the total score.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $code
 * @property string $name
 * @property string $source
 * @property string $weight
 * @property bool $is_active
 * @property int $sort_order
 */
final class CreditScorecardFactor extends BaseEnterpriseModel
{
    public const string OverdueRatio = 'overdue_ratio';

    public const string MaxDaysOverdue = 'max_days_overdue';

    public const string LimitUtilisation = 'limit_utilisation';

    public const string RelationshipMonths = 'relationship_months';

    public const string Manual = 'manual';

    /** @var list<string> */
    public const array Sources = [self::OverdueRatio, self::MaxDaysOverdue, self::LimitUtilisation, self::RelationshipMonths, self::Manual];

    protected $table = 'credit_scorecard_factors';

    protected function casts(): array
    {
        return [
            'weight' => 'decimal:2',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }
}
