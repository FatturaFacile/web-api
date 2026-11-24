<?php

declare(strict_types=1);

namespace Modules\Associations\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Associations\Models\Donation;
use Modules\Associations\Support\TenantRules;

final class RecordDonationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'donor_id' => ['sometimes', 'nullable', 'uuid', TenantRules::exists('assoc_donors')],
            'campaign_id' => ['sometimes', 'nullable', 'uuid', TenantRules::exists('assoc_campaigns')],
            'kind' => ['required', Rule::in([Donation::Money, Donation::InKind])],
            'amount' => ['required', 'regex:/^\d{1,12}(\.\d{1,2})?$/'],
            'in_kind_description' => ['required_if:kind,'.Donation::InKind, 'nullable', 'string', 'max:2000'],
            'received_on' => ['required', 'date', 'before_or_equal:today'],
            'payment_method' => ['required_if:kind,'.Donation::Money, 'nullable', Rule::in(['cash', 'bank'])],
            'purpose' => ['sometimes', 'nullable', 'string', 'max:500'],
            'revenue_account_id' => ['sometimes', 'nullable', 'uuid', TenantRules::exists('chart_of_accounts')],
            'cost_center_id' => ['sometimes', 'nullable', 'uuid', TenantRules::exists('cost_centers')],
        ];
    }
}
