<?php

declare(strict_types=1);

namespace Modules\Rebates\Http\Requests;

use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Modules\Rebates\Models\RebateAgreement;

/**
 * A rebate agreement with its scale of tiers. The scale is replaced as a whole on every write that sends it.
 */
final class AgreementRequest extends RebatesFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = $this->forMethod([
            'code' => ['required', 'string', 'max:40'],
            'name' => ['required', 'string', 'max:160'],
            'direction' => ['required', Rule::in(RebateAgreement::Directions)],
            'contact_id' => ['required', 'uuid', $this->ownRow('contacts')],
            'tier_mode' => ['sometimes', Rule::in(RebateAgreement::TierModes)],
            'starts_on' => ['required', 'date_format:Y-m-d'],
            'ends_on' => ['required', 'date_format:Y-m-d'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'rebate_type' => ['sometimes', Rule::in(RebateAgreement::RebateTypes)],
            'supplier_contract_id' => ['nullable', 'uuid', $this->ownRow('supplier_contracts')],
            'fixed_bonus_target' => ['nullable', 'numeric', 'min:0', 'max:9999999999999'],
            'fixed_bonus_amount' => ['nullable', 'numeric', 'min:0', 'max:9999999999999'],
            'accrual_frequency' => ['sometimes', Rule::in(RebateAgreement::AccrualFrequencies)],
            'accrual_account_id' => ['nullable', 'uuid'],
            'income_account_id' => ['nullable', 'uuid'],
            'version' => ['sometimes', 'integer', 'min:1'],
            'tiers' => [Rule::requiredIf(fn (): bool => $this->input('rebate_type', RebateAgreement::TieredVolume) === RebateAgreement::TieredVolume), 'array', 'max:20'],
            'tiers.*.threshold_amount' => ['required', 'numeric', 'min:0', 'max:9999999999999'],
            'tiers.*.rate_percent' => ['required', 'numeric', 'min:0', 'max:100'],
            'tiers.*.flat_amount' => ['nullable', 'numeric', 'min:0', 'max:9999999999999'],
        ]);

        return $this->isMethod('POST') ? [...$rules, 'ends_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:starts_on']] : $rules;
    }

    /**
     * The thresholds of the scale are all different.
     *
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $thresholds = array_map(static fn (mixed $tier): string => number_format((float) ($tier['threshold_amount'] ?? 0), 2, '.', ''), (array) $this->input('tiers', []));

            if (count($thresholds) !== count(array_unique($thresholds))) {
                $validator->errors()->add('tiers', __('rebate_errors.agreement.tiers_distinct'));
            }

            if ($this->input('rebate_type') === RebateAgreement::FixedBonus && ($this->input('fixed_bonus_target') === null || $this->input('fixed_bonus_amount') === null)) {
                $validator->errors()->add('fixed_bonus_target', __('rebate_errors.agreement.bonus_required'));
            }
        }];
    }
}
