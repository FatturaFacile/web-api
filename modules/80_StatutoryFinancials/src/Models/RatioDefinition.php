<?php

declare(strict_types=1);

namespace Modules\StatutoryFinancials\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Enterprise\MultiTenant\Concerns\AllowsGlobalRecords;

/**
 * Financial ratio and crisis indicator: labels, descriptions, thresholds and alert messages (all seeded).
 *
 * @property string $code
 * @property string $family
 * @property string $unit
 * @property string|null $direction "min": the value must stay at or above the threshold; "max": at or below.
 * @property string|null $warning_threshold
 * @property string|null $critical_threshold
 * @property bool $is_crisis_indicator
 * @property int $sort_order
 * @property array<string, string> $labels
 * @property array<string, string> $descriptions
 * @property array<string, array{warning?: string, critical?: string}> $alert_messages
 */
final class RatioDefinition extends BaseEnterpriseModel
{
    use AllowsGlobalRecords;

    protected $table = 'sfs_ratio_definitions';

    protected function casts(): array
    {
        return [
            'warning_threshold' => 'decimal:4',
            'critical_threshold' => 'decimal:4',
            'is_crisis_indicator' => 'boolean',
            'sort_order' => 'integer',
            'labels' => 'array',
            'descriptions' => 'array',
            'alert_messages' => 'array',
        ];
    }
}
