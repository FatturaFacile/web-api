<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Http\Requests;

use App\Services\Tenancy\TenantContext;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Validation\Rule;
use Modules\Manufacturing\Models\MfgMachineSignalMap;
use Modules\Manufacturing\Models\MfgMachineStateEvent;

/**
 * Validation of the mapping of a protocol signal to a machine signal. The source key must have the shape of its protocol
 * (Modbus `[unit:]holding|input|coil|discrete:address` with a data type, MQTT topic filter with `+` as a whole level and
 * `#` only as the last level), numeric signals take no state map, and the same source key cannot feed the same signal of a
 * machine twice.
 */
final class SaveMachineSignalMapRequest extends ManufacturingRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'machine_id' => ['required', 'uuid', $this->tenantOwns('mfg_machine_centers')],
            'gateway_id' => ['nullable', 'uuid', $this->tenantOwns('mfg_edge_gateways')],
            'protocol' => ['required', 'string', Rule::in(MfgMachineSignalMap::Protocols)],
            'source_key' => ['required', 'string', 'max:255'],
            'signal' => ['required', 'string', Rule::in(MfgMachineSignalMap::signals())],
            'data_type' => ['nullable', 'string', Rule::in(MfgMachineSignalMap::DataTypes)],
            'word_order' => ['sometimes', 'string', 'in:big,little'],
            'scale_factor' => ['sometimes', 'numeric', 'decimal:0,6', 'between:-1000000000,1000000000'],
            'offset_value' => ['sometimes', 'numeric', 'decimal:0,6', 'between:-1000000000,1000000000'],
            'value_path' => ['nullable', 'string', 'max:255', 'regex:/^[A-Za-z0-9_-]+(\.[A-Za-z0-9_-]+)*$/'],
            'state_map' => ['nullable', 'array', 'max:50'],
            'state_map.*' => ['string', Rule::in(MfgMachineStateEvent::States)],
            'unit' => ['nullable', 'string', 'max:12'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * Cross-field checks.
     *
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $data = $this->all();
            $protocol = (string) ($data['protocol'] ?? '');
            $key = (string) ($data['source_key'] ?? '');
            $signal = (string) ($data['signal'] ?? '');

            if ($protocol === 'modbus' && preg_match('/^(\d{1,3}:)?(holding|input|coil|discrete):\d{1,5}$/', $key) !== 1) {
                $validator->errors()->add('source_key', 'A Modbus source key is [unit:]holding|input|coil|discrete:address.');
            }

            if ($protocol === 'modbus' && in_array($signal, MfgMachineSignalMap::NumericSignals, true) && empty($data['data_type'])) {
                $validator->errors()->add('data_type', 'A Modbus numeric signal needs a data type.');
            }

            if ($protocol === 'mqtt' && ! $this->validTopicFilter($key)) {
                $validator->errors()->add('source_key', 'The MQTT topic filter is not valid.');
            }

            if ($signal !== MfgMachineSignalMap::StateSignal && ! empty($data['state_map'])) {
                $validator->errors()->add('state_map', 'Only a state signal takes a state map.');
            }

            if ($validator->errors()->isEmpty() && $this->isDuplicate($data)) {
                $validator->errors()->add('source_key', 'The signal of this machine is already mapped from this source.');
            }
        }];
    }

    private function validTopicFilter(string $filter): bool
    {
        $levels = explode('/', $filter);

        foreach ($levels as $index => $level) {
            if ($level === '') {
                return false;
            }

            if ($level === '#' && $index !== count($levels) - 1) {
                return false;
            }

            if ($level !== '#' && $level !== '+' && (str_contains($level, '#') || str_contains($level, '+'))) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function isDuplicate(array $data): bool
    {
        return MfgMachineSignalMap::query()
            ->where('tenant_id', app(TenantContext::class)->getTenantId())
            ->where('machine_id', $data['machine_id'])
            ->where('protocol', $data['protocol'])
            ->where('source_key', $data['source_key'])
            ->where('signal', $data['signal'])
            ->when($this->route('id') !== null, fn ($query) => $query->whereKeyNot($this->route('id')))
            ->exists();
    }
}
