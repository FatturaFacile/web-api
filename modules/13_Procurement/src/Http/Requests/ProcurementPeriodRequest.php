<?php

declare(strict_types=1);

namespace Modules\Procurement\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A period (`from`, `to`) for analytics and supplier evaluation; defaults to the current year up to today.
 */
final class ProcurementPeriodRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'comments' => ['nullable', 'string', 'max:2000'],
            'dimension' => ['nullable', 'string', 'in:supplier,category,cost_center'],
        ];
    }

    public function from(): string
    {
        return (string) ($this->validated('from') ?? now()->startOfYear()->toDateString());
    }

    public function to(): string
    {
        return (string) ($this->validated('to') ?? now()->toDateString());
    }
}
