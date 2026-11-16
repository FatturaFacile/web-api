<?php

declare(strict_types=1);

namespace Modules\Planning\Models;

use App\Support\Database\Concerns\ProtectsRecords;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The planned quantity of an item in a month of a scenario, on the demand side or on the supply side. Lines of a
 * scenario that is not a draft cannot be created, changed or removed.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $scenario_id
 * @property string $line_type
 * @property string|null $item_id
 * @property string $item_code
 * @property string|null $item_name
 * @property Carbon $period
 * @property string $quantity
 * @property string $source
 * @property string|null $notes
 */
final class PlanningPlanLine extends BaseEnterpriseModel
{
    use ProtectsRecords;

    public const string Demand = 'demand';

    public const string Supply = 'supply';

    public const array Types = [self::Demand, self::Supply];

    public const string Manual = 'manual';

    public const string Forecast = 'forecast';

    public const string Copied = 'copied';

    public const array Sources = [self::Manual, self::Forecast, self::Copied];

    protected $table = 'planning_plan_lines';

    protected function casts(): array
    {
        return [
            'period' => 'date',
            'quantity' => 'decimal:3',
        ];
    }

    /**
     * @return BelongsTo<PlanningScenario, $this>
     */
    public function scenario(): BelongsTo
    {
        return $this->belongsTo(PlanningScenario::class, 'scenario_id');
    }

    protected function recordProtectionViolation(string $operation): ?string
    {
        $status = $this->scenario_id === null ? null : PlanningScenario::query()->whereKey($this->scenario_id)->value('status');

        return $status !== null && $status !== PlanningScenario::Draft ? __('planning_errors.scenario.not_draft') : null;
    }
}
