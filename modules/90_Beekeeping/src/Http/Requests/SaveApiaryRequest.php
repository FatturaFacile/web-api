<?php

declare(strict_types=1);

namespace Modules\Beekeeping\Http\Requests;

use Illuminate\Validation\Rule;
use Modules\Beekeeping\Domain\Enums\ApiaryType;
use Modules\Beekeeping\Domain\Enums\Country;

final class SaveApiaryRequest extends BeekeepingRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $creating = $this->isMethod('POST');
        $required = $creating ? 'required' : 'sometimes';
        $code = Rule::unique('bee_apiaries', 'code')->where('tenant_id', $this->tenantId())->whereNull('deleted_at');

        if (! $creating) {
            $code->ignore((string) $this->route('id'));
        }

        return [
            'code' => [$required, 'string', 'max:40', $code],
            'name' => [$required, 'string', 'max:200'],
            'country' => [$required, Rule::enum(Country::class)],
            'municipality' => ['nullable', 'string', 'max:120'],
            'province_canton' => ['nullable', 'string', 'max:4'],
            'registry_code' => ['nullable', 'string', 'max:60'],
            'apiary_type' => ['sometimes', Rule::enum(ApiaryType::class)],
            'latitude' => ['nullable', 'numeric', 'between:-90,90', 'required_with:longitude'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180', 'required_with:latitude'],
            'altitude_m' => ['nullable', 'integer', 'between:-100,5000'],
            'foraging_radius_m' => ['sometimes', 'integer', 'between:100,10000'],
            'flora_notes' => ['nullable', 'string', 'max:2000'],
            'blooming_calendar' => ['nullable', 'string', 'max:2000'],
            'weather_notes' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
