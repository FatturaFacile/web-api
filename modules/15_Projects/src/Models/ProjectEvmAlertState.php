<?php

declare(strict_types=1);

namespace Modules\Projects\Models;

use Carbon\CarbonInterface;
use Enterprise\Foundation\Models\BaseEnterpriseModel;

/**
 * Whether a threshold breach (CPI, SPI or budget absorption of a node) is currently open. An alert is notified once when
 * the breach opens and again only after it was resolved and breached anew, so repeated snapshots never repeat it.
 *
 * @property string $id
 * @property string|null $tenant_id
 * @property string $project_id
 * @property string|null $wbs_node_id
 * @property string $metric
 * @property string $node_key
 * @property string $severity
 * @property string $metric_value
 * @property string $threshold
 * @property bool $is_open
 * @property CarbonInterface $opened_at
 * @property CarbonInterface|null $resolved_at
 * @property CarbonInterface|null $last_notified_at
 */
final class ProjectEvmAlertState extends BaseEnterpriseModel
{
    protected $table = 'project_evm_alert_states';

    protected function casts(): array
    {
        return [
            'metric_value' => 'decimal:4',
            'threshold' => 'decimal:4',
            'is_open' => 'boolean',
            'opened_at' => 'datetime',
            'resolved_at' => 'datetime',
            'last_notified_at' => 'datetime',
        ];
    }
}
