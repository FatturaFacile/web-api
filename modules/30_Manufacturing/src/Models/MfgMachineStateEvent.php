<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Support\Carbon;

/**
 * A machine state change reported by an edge gateway. `event_uuid` is the idempotency key: a retried or replayed
 * batch (store-and-forward buffer) never creates a second row.
 *
 * @property string $id
 * @property string|null $tenant_id
 * @property string $gateway_id
 * @property string $machine_id
 * @property string $event_uuid
 * @property string $state RUNNING|SETUP|IDLE|FAULT|MAINTENANCE
 * @property Carbon $occurred_at
 * @property string|null $production_order_operation_id
 * @property array<string, mixed>|null $payload
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class MfgMachineStateEvent extends BaseEnterpriseModel
{
    public const array States = ['RUNNING', 'SETUP', 'IDLE', 'FAULT', 'MAINTENANCE'];

    protected $table = 'mfg_machine_state_events';

    protected function casts(): array
    {
        return ['occurred_at' => 'datetime', 'payload' => 'array'];
    }
}
