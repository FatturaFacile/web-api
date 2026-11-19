<?php

declare(strict_types=1);

namespace Modules\CRM\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class PreviewLeadScoreRequest extends FormRequest
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
            'lead_id' => ['nullable', 'uuid', 'required_without:lead'],
            'lead' => ['nullable', 'array', 'required_without:lead_id'],
            'lead.first_name' => ['nullable', 'string', 'max:100'],
            'lead.last_name' => ['nullable', 'string', 'max:100'],
            'lead.company_name' => ['nullable', 'string', 'max:200'],
            'lead.title' => ['nullable', 'string', 'max:100'],
            'lead.email' => ['nullable', 'email', 'max:150'],
            'lead.phone' => ['nullable', 'string', 'max:50'],
            'lead.website' => ['nullable', 'string', 'max:255'],
            'lead.country' => ['nullable', 'string', 'size:2'],
            'lead.estimated_budget' => ['nullable', 'numeric', 'min:0'],
            'lead.vat_number' => ['nullable', 'string', 'max:30'],
            'lead.industry' => ['nullable', 'string', 'max:100'],
            'lead.employee_count' => ['nullable', 'integer', 'min:0'],
            'lead.annual_revenue_estimate' => ['nullable', 'numeric', 'min:0'],
            'lead.state_region' => ['nullable', 'string', 'max:50'],
            'lead.product_line' => ['nullable', 'string', 'max:100'],
        ];
    }
}
