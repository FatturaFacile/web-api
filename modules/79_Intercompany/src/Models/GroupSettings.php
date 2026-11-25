<?php

declare(strict_types=1);

namespace Modules\Intercompany\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;

/**
 * Mirror posting, payment sync and netting options of a consolidation group (one row per group).
 *
 * @property bool $auto_mirror
 * @property bool $sync_payments
 * @property bool $netting_enabled
 * @property string $amount_tolerance
 * @property int $date_tolerance_days
 * @property string $mirror_mode
 * @property string $default_standard
 * @property string $group_id
 */
final class GroupSettings extends BaseEnterpriseModel
{
    protected $table = 'ic_group_settings';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'auto_mirror' => 'boolean',
            'sync_payments' => 'boolean',
            'netting_enabled' => 'boolean',
            'amount_tolerance' => 'decimal:4',
            'date_tolerance_days' => 'integer',
        ];
    }
}
