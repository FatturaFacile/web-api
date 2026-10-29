<?php

declare(strict_types=1);

namespace Modules\Projects\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class StoreChangeOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'order_number' => ['required', 'string', 'max:100'],
            'title' => ['required', 'string', 'max:255'],
            'scope_description' => ['required', 'string'],
            'cost_impact_amount' => ['required', 'numeric'],
            'revenue_impact_amount' => ['nullable', 'numeric'],
            'schedule_impact_days' => ['nullable', 'integer'],
            'lines' => ['nullable', 'array'],
        ];
    }
}
