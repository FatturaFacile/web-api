<?php

declare(strict_types=1);

namespace Modules\KpiDashboards\Infrastructure\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Modules\KpiDashboards\Domain\Enums\KpiCode;

/**
 * An alert rule such as "cash runway < 3 months": the warning and critical boundaries share one comparison operator.
 *
 * @property string $id
 * @property KpiCode $kpi_code
 * @property string $operator one of <, <=, >, >=
 * @property string|null $warning_value
 * @property string|null $critical_value
 * @property list<string>|null $channels_json
 * @property int $cooldown_minutes
 * @property bool $is_active
 */
final class KpiThresholdRule extends BaseEnterpriseModel
{
    protected $table = 'kpi_threshold_rules';

    protected function casts(): array
    {
        return [
            'kpi_code' => KpiCode::class,
            'channels_json' => 'array',
            'cooldown_minutes' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * The severity the value reaches ('critical', 'warning') or null when it is within bounds.
     */
    public function severityOf(float $value): ?string
    {
        foreach (['critical' => $this->critical_value, 'warning' => $this->warning_value] as $severity => $boundary) {
            if ($boundary !== null && $this->breaches($value, (float) $boundary)) {
                return $severity;
            }
        }

        return null;
    }

    private function breaches(float $value, float $boundary): bool
    {
        return match ($this->operator) {
            '<' => $value < $boundary,
            '<=' => $value <= $boundary,
            '>' => $value > $boundary,
            '>=' => $value >= $boundary,
            default => false,
        };
    }
}
