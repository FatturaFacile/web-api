<?php

declare(strict_types=1);

namespace Modules\MarketingAutomation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class SaveSegmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Role access to the module is enforced by the module.access middleware of the route group.
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $partial = $this->isMethod('PUT') || $this->isMethod('PATCH') || $this->routeIs('marketing-automation.segments.preview') ? 'sometimes' : 'required';

        return [
            'name' => [$partial, 'string', 'max:160'],
            'code' => ['nullable', 'string', 'max:80'],
            'description' => ['nullable', 'string', 'max:2000'],
            'auto_refresh_hourly' => ['sometimes', 'boolean'],
            'criteria' => ['sometimes', 'array'],
            'criteria.recency_days_min' => ['nullable', 'integer', 'min:0'],
            'criteria.recency_days_max' => ['nullable', 'integer', 'min:0'],
            'criteria.frequency_min' => ['nullable', 'integer', 'min:0'],
            'criteria.frequency_max' => ['nullable', 'integer', 'min:0'],
            'criteria.monetary_min' => ['nullable', 'numeric', 'min:0'],
            'criteria.monetary_max' => ['nullable', 'numeric', 'min:0'],
            'criteria.exclude_insolvent' => ['sometimes', 'boolean'],
            'criteria.max_overdue_balance' => ['nullable', 'numeric', 'min:0'],
            'criteria.purchased_product_category_ids' => ['sometimes', 'array'],
            'criteria.purchased_product_category_ids.*' => ['uuid'],
            'criteria.purchased_skus' => ['sometimes', 'array'],
            'criteria.purchased_skus.*' => ['string', 'max:80'],
            'criteria.purchased_window_months' => ['sometimes', 'array'],
            'criteria.purchased_window_months.min' => ['nullable', 'integer', 'min:0'],
            'criteria.purchased_window_months.max' => ['nullable', 'integer', 'min:0'],
            'criteria.geographic_canton_or_province' => ['sometimes', 'array'],
            'criteria.geographic_canton_or_province.*' => ['string', 'max:10'],
            'criteria.locale' => ['nullable', 'in:it,de,fr,en'],
            'criteria.rfm_labels' => ['sometimes', 'array'],
            'criteria.rfm_labels.*' => ['in:champions,loyal,promising,new_customers,at_risk,hibernating,lost'],
        ];
    }
}
