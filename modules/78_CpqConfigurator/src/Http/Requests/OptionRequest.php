<?php

declare(strict_types=1);

namespace Modules\CpqConfigurator\Http\Requests;

use App\Services\Tenancy\TenantContext;
use Illuminate\Validation\Rule;
use Modules\CpqConfigurator\Models\CpqCharacteristicOption;

/**
 * An option of a value-list characteristic.
 */
final class OptionRequest extends CpqFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $characteristicId = $this->isMethod('POST') ? (string) $this->route('id') : CpqCharacteristicOption::query()->find($this->route('id'))?->characteristic_id;

        return [
            'code' => [$this->presence(), 'string', 'max:40', 'regex:/^[A-Za-z0-9._-]+$/', Rule::unique('cpq_characteristic_options', 'code')->where('tenant_id', app(TenantContext::class)->getTenantId())->where('characteristic_id', $characteristicId)->whereNull('deleted_at')->ignore($this->isMethod('POST') ? null : $this->route('id'))],
            ...$this->i18n('label', true),
            ...$this->i18n('description', false, 1000),
            'part_number_code' => ['nullable', 'string', 'max:12', 'regex:/^[A-Za-z0-9]+$/'],
            'is_default' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
            'properties' => ['nullable', 'array'],
            'visual' => ['nullable', 'array'],
            'visual.layer' => ['nullable', 'string', 'max:30'],
            'visual.shape' => ['nullable', 'string', 'max:30'],
            'visual.color' => ['nullable', 'string', 'max:20'],
            'visual.z' => ['nullable', 'integer', 'min:0', 'max:1000'],
            'visual.slot' => ['nullable', 'integer', 'min:1', 'max:12'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ];
    }
}
