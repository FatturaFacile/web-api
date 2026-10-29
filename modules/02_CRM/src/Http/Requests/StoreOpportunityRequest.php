<?php

declare(strict_types=1);

namespace Modules\CRM\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class StoreOpportunityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'account_id' => ['required', 'uuid'],
            'contact_id' => ['nullable', 'uuid'],
            'name' => ['required', 'string', 'max:200'],
            'amount' => ['required', 'numeric', 'min:0'],
            'currency' => ['nullable', 'string', 'size:3'],
            'stage_code' => ['nullable', 'string', 'max:50'],
            'forecast_category' => ['nullable', 'string', 'in:pipeline,best_case,commit,closed,omitted'],
            'expected_close_date' => ['nullable', 'date'],
            'owner_user_id' => ['nullable', 'uuid'],
            'campaign_id' => ['nullable', 'uuid'],
            'lead_id' => ['nullable', 'uuid'],
            'discount_percentage' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'line_items' => ['nullable', 'array'],
            'line_items.*.description' => ['required_with:line_items', 'string'],
            'line_items.*.quantity' => ['required_with:line_items', 'numeric', 'min:0.01'],
            'line_items.*.unit_price' => ['required_with:line_items', 'numeric', 'min:0'],
            'tags' => ['nullable', 'array'],
        ];
    }
}
