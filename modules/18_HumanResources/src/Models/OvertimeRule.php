<?php

declare(strict_types=1);

namespace Modules\HumanResources\Models;

use Carbon\CarbonInterface;
use Database\Factories\OvertimeRuleFactory;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Builder;

/**
 * A company rule that turns worked minutes into overtime: above threshold_minutes per day (basis "daily") or per ISO
 * week (basis "weekly"), paid with premium_percent on top of the hourly rate. Thresholds and premiums come from the
 * applicable law or collective agreement and are entered by the company; none is shipped.
 */
#[UseFactory(OvertimeRuleFactory::class)]
final class OvertimeRule extends BaseEnterpriseModel
{
    public const string BasisDaily = 'daily';

    public const string BasisWeekly = 'weekly';

    protected $table = 'hr_overtime_rules';

    protected function casts(): array
    {
        return [
            'threshold_minutes' => 'integer',
            'premium_percent' => 'decimal:2',
            'priority' => 'integer',
            'valid_from' => 'date:Y-m-d',
            'valid_to' => 'date:Y-m-d',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Active rules valid on a day.
     *
     * @param  Builder<OvertimeRule>  $query
     * @return Builder<OvertimeRule>
     */
    public function scopeEffectiveOn(Builder $query, CarbonInterface|string $day): Builder
    {
        $date = $day instanceof CarbonInterface ? $day->format('Y-m-d') : $day;

        return $query->where('is_active', true)
            ->where(static fn (Builder $inner) => $inner->whereNull('valid_from')->orWhereDate('valid_from', '<=', $date))
            ->where(static fn (Builder $inner) => $inner->whereNull('valid_to')->orWhereDate('valid_to', '>=', $date));
    }
}
