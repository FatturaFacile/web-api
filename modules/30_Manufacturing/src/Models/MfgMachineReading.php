<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Support\Carbon;

/**
 * A numeric value of a machine signal (cumulative piece counter, spindle speed, energy, power) decoded from a gateway
 * message. `reading_uuid` is derived from the content of the sample, so a replayed message never creates a second row.
 *
 * @property string $id
 * @property string|null $tenant_id
 * @property string $gateway_id
 * @property string $machine_id
 * @property string $reading_uuid
 * @property string $signal piece_count|spindle_speed|energy_kwh|power_kw
 * @property string $value
 * @property string|null $unit
 * @property Carbon $occurred_at
 * @property string|null $production_order_operation_id
 * @property array<string, mixed>|null $payload
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class MfgMachineReading extends BaseEnterpriseModel
{
    protected $table = 'mfg_machine_readings';

    protected function casts(): array
    {
        return ['value' => 'decimal:4', 'occurred_at' => 'datetime', 'payload' => 'array'];
    }
}
