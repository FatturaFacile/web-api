<?php

declare(strict_types=1);

namespace Modules\Associations\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Associations\Models\Reimbursement;
use Modules\Associations\Support\TenantRules;

final class StoreReimbursementRequest extends FormRequest
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
        $mileage = 'required_if:kind,'.Reimbursement::Mileage;

        return [
            'member_id' => ['required', 'uuid', TenantRules::exists('assoc_members')],
            'volunteer_id' => ['sometimes', 'nullable', 'uuid', TenantRules::exists('assoc_volunteers')],
            'kind' => ['required', Rule::in([Reimbursement::Mileage, Reimbursement::Expense])],
            'incurred_on' => ['required', 'date'],
            'description' => ['required', 'string', 'max:500'],
            'vehicle' => [$mileage, 'nullable', 'string', 'max:100'],
            'plate' => [$mileage, 'nullable', 'string', 'max:20'],
            'distance_km' => [$mileage, 'nullable', 'regex:/^\d{1,7}(\.\d{1,2})?$/'],
            'unit_price' => [$mileage, 'nullable', 'regex:/^\d{1,4}(\.\d{1,4})?$/'],
            'amount' => ['required_if:kind,'.Reimbursement::Expense, 'nullable', 'regex:/^\d{1,10}(\.\d{1,2})?$/'],
            'project_id' => ['sometimes', 'nullable', 'uuid', TenantRules::exists('projects')],
            'campaign_id' => ['sometimes', 'nullable', 'uuid', TenantRules::exists('assoc_campaigns')],
            'cost_center_id' => ['sometimes', 'nullable', 'uuid', TenantRules::exists('cost_centers')],
            'expense_account_id' => ['sometimes', 'nullable', 'uuid', TenantRules::exists('chart_of_accounts')],
        ];
    }
}
