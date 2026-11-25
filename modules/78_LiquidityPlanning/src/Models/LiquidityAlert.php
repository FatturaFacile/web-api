<?php

declare(strict_types=1);

namespace Modules\LiquidityPlanning\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Support\Carbon;

/**
 * A crisis early-warning alert (negative bank balance, DSCR, capital loss, covenant, leverage). The texts are translation
 * keys of the seeded dictionary plus the params to fill in; `fingerprint` identifies the condition so that the same one is
 * not raised twice while it lasts.
 *
 * @property string $id
 * @property string|null $tenant_id
 * @property string $type
 * @property string $severity
 * @property string $status
 * @property string $fingerprint
 * @property string $title_key
 * @property string $message_key
 * @property array<string, mixed>|null $params
 * @property Carbon $detected_at
 * @property Carbon|null $acknowledged_at
 * @property string|null $acknowledged_by
 * @property Carbon|null $resolved_at
 * @property Carbon|null $last_notified_at
 */
final class LiquidityAlert extends BaseEnterpriseModel
{
    public const string Open = 'open';

    public const string Acknowledged = 'acknowledged';

    public const string Resolved = 'resolved';

    protected $table = 'lp_alerts';

    protected function casts(): array
    {
        return [
            'params' => 'array',
            'detected_at' => 'datetime',
            'acknowledged_at' => 'datetime',
            'resolved_at' => 'datetime',
            'last_notified_at' => 'datetime',
        ];
    }
}
