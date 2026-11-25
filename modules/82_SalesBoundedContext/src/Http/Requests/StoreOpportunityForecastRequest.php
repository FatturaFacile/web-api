<?php

declare(strict_types=1);

namespace Modules\SalesBoundedContext\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\SalesBoundedContext\Domain\Enums\ForecastCategory;

final class StoreOpportunityForecastRequest extends FormRequest
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
            'dossier_id' => ['nullable', 'uuid'],
            'lead_id' => ['nullable', 'uuid'],
            'opportunity_code' => ['nullable', 'string', 'max:40'],
            'title' => ['required', 'string', 'max:180'],
            'kanban_stage' => ['required', 'string', 'max:40'],
            'win_probability_pct' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'gross_amount' => ['required', 'numeric', 'min:0'],
            'currency' => ['nullable', 'string', 'size:3'],
            'forecast_category' => ['nullable', Rule::in(ForecastCategory::values())],
            'sales_quota_target' => ['nullable', 'numeric', 'min:0'],
            'owner_name' => ['nullable', 'string', 'max:120'],
            'expected_close_date' => ['nullable', 'date'],
        ];
    }
}
