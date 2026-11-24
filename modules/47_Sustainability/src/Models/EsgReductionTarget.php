<?php

declare(strict_types=1);

namespace Modules\Sustainability\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Support\Carbon;

/**
 * An absolute emission reduction target against a baseline year. The baseline is read from the inventory and frozen
 * when the target is activated; the target then ends as achieved, missed or cancelled.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $name
 * @property string $scope
 * @property int $baseline_year
 * @property string|null $baseline_co2e_kg
 * @property int $target_year
 * @property string $reduction_percent
 * @property string $status
 * @property string|null $notes
 * @property Carbon|null $activated_at
 * @property Carbon|null $evaluated_at
 */
final class EsgReductionTarget extends BaseEnterpriseModel
{
    public const string Draft = 'draft';

    public const string Active = 'active';

    public const string Achieved = 'achieved';

    public const string Missed = 'missed';

    public const string Cancelled = 'cancelled';

    /** @var list<string> */
    public const array Statuses = [self::Draft, self::Active, self::Achieved, self::Missed, self::Cancelled];

    /** @var list<string> */
    public const array Scopes = ['scope_1', 'scope_2', 'scope_3', 'total'];

    protected $table = 'esg_reduction_targets';

    protected function casts(): array
    {
        return [
            'baseline_year' => 'integer',
            'baseline_co2e_kg' => 'decimal:4',
            'reduction_percent' => 'decimal:2',
            'target_year' => 'integer',
            'activated_at' => 'datetime',
            'evaluated_at' => 'datetime',
        ];
    }
}
