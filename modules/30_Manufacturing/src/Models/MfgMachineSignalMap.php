<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Support\Carbon;

/**
 * Maps one source signal of an industrial protocol to a signal of a machine: an OPC UA node id, an MQTT topic (with the
 * + and # wildcards, or a Sparkplug B metric as topic/metric) or a Modbus register (`[unit:]function:address`).
 * `signal` says what the value means (machine state, piece counter, spindle speed, energy, power); the raw value is
 * decoded with `data_type` and `word_order` (Modbus registers), read from `value_path` (JSON payloads), translated with
 * `state_map` (state signals) or converted as `raw * scale_factor + offset_value` (numeric signals).
 *
 * @property string $id
 * @property string|null $tenant_id
 * @property string $machine_id
 * @property string|null $gateway_id null: valid for every gateway of the company
 * @property string $protocol opcua|mqtt|modbus
 * @property string $source_key
 * @property string $signal state|piece_count|spindle_speed|energy_kwh|power_kw
 * @property string|null $data_type bool|int16|uint16|int32|uint32|float32
 * @property string $word_order big|little
 * @property string $scale_factor
 * @property string $offset_value
 * @property string|null $value_path
 * @property array<string, string>|null $state_map raw value => RUNNING|SETUP|IDLE|FAULT|MAINTENANCE
 * @property string|null $unit
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class MfgMachineSignalMap extends BaseEnterpriseModel
{
    public const array Protocols = ['opcua', 'mqtt', 'modbus'];

    public const string StateSignal = 'state';

    /** Numeric signals stored as readings. */
    public const array NumericSignals = ['piece_count', 'spindle_speed', 'energy_kwh', 'power_kw'];

    public const array DataTypes = ['bool', 'int16', 'uint16', 'int32', 'uint32', 'float32'];

    protected $table = 'mfg_machine_signal_maps';

    /**
     * @return list<string>
     */
    public static function signals(): array
    {
        return [self::StateSignal, ...self::NumericSignals];
    }

    protected function casts(): array
    {
        return ['state_map' => 'array', 'is_active' => 'boolean'];
    }
}
