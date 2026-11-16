<?php

declare(strict_types=1);

namespace Modules\CRM\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class StoreLeadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'first_name' => ['nullable', 'string', 'max:100'],
            'last_name' => ['nullable', 'string', 'max:100'],
            'company_name' => ['nullable', 'string', 'max:200'],
            'title' => ['nullable', 'string', 'max:100'],
            'email' => ['nullable', 'email', 'max:150'],
            'phone' => ['nullable', 'string', 'max:50'],
            'website' => ['nullable', 'string', 'max:255'],
            'country' => ['nullable', 'string', 'size:2'],
            'lead_source_code' => ['nullable', 'string', 'max:50'],
            'campaign_id' => ['nullable', 'uuid'],
            'assigned_user_id' => ['nullable', 'uuid'],
            'estimated_budget' => ['nullable', 'numeric', 'min:0'],
            'tags' => ['nullable', 'array'],
            'custom_fields' => ['nullable', 'array'],
            'vat_number' => ['nullable', 'string', 'max:30'],
            'industry' => ['nullable', 'string', 'max:100'],
            'region' => ['nullable', 'string', 'max:100'],
            'product_line' => ['nullable', 'string', 'max:100'],
            'company_size' => ['nullable', 'integer', 'min:0'],
            'estimated_revenue' => ['nullable', 'numeric', 'min:0'],
            'duplicate_strategy' => ['nullable', 'in:merge,notify,create'],
        ];
    }
}
