<?php

declare(strict_types=1);

namespace Modules\ForeignVatRefund\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Support\Carbon;

/**
 * Remembers which alert (and at which 60/30/15 day level) was already sent, so a daily run never repeats it.
 *
 * @property string $alert_key
 * @property int $level
 * @property Carbon $sent_at
 */
final class ForeignVatAlertLog extends BaseEnterpriseModel
{
    protected $table = 'foreign_vat_alert_logs';

    protected function casts(): array
    {
        return ['level' => 'integer', 'sent_at' => 'datetime'];
    }
}
