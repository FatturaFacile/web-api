<?php

declare(strict_types=1);

namespace App\Http\Requests\Concerns;

use App\Enums\CustomsOriginType;
use Illuminate\Validation\Rule;

/**
 * Validation rules shared by every request that persists product customs / origin data.
 */
trait CustomsOriginRules
{
    /**
     * @return array<string, array<int, mixed>>
     */
    protected function customsOriginRules(): array
    {
        return [
            'customs_taric_code_id' => ['nullable', 'uuid', Rule::exists('customs_taric_codes', 'id')],
            'taric_code' => ['nullable', 'string', 'regex:/^\d{6}(\d{2}(\d{2})?)?$/'],
            'country_of_origin' => ['nullable', 'string', 'size:2', 'regex:/^[A-Za-z]{2}$/'],
            'origin_type' => ['nullable', Rule::enum(CustomsOriginType::class)],
            'made_in_label' => ['nullable', 'string', 'max:255'],
            'preferential_agreement_id' => ['nullable', 'uuid', Rule::exists('free_trade_agreements', 'id')],
            'eur1_required' => ['sometimes', 'boolean'],
            'origin_rule_notes' => ['nullable', 'string'],
            'swiss_hs_code' => ['nullable', 'string', 'regex:/^\d{8}$/'],
            'supplementary_unit' => ['nullable', 'string', 'max:10'],
            'customs_duty_rate' => ['nullable', 'numeric', 'min:0', 'max:999'],
            'net_weight_kg' => ['nullable', 'numeric', 'min:0', 'max:999999999'],
            'gross_weight_kg' => ['nullable', 'numeric', 'min:0', 'max:999999999'],
            'packages_count' => ['nullable', 'integer', 'min:0', 'max:99999999'],
            'preferential_origin_eligible' => ['sometimes', 'boolean'],
        ];
    }
}
