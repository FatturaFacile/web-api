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
            'tiers' => ['required', 'array', 'min:1', 'max:20'],
            'tiers.*.threshold_amount' => ['required', 'numeric', 'min:0', 'max:9999999999999'],
            'tiers.*.rate_percent' => ['required', 'numeric', 'min:0', 'max:100'],
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
        }];
    }
}
