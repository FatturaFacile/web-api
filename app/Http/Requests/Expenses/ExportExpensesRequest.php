<?php

declare(strict_types=1);

namespace App\Http\Requests\Expenses;

use Illuminate\Foundation\Http\FormRequest;

class ExportExpensesRequest extends FormRequest
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
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'category_id' => ['nullable', 'uuid'],
            'project_id' => ['nullable', 'uuid'],
            'contact_id' => ['nullable', 'uuid'],
            'user_id' => ['nullable', 'uuid'],
            'status' => ['nullable', 'string', 'in:draft,submitted,approved,rejected,all'],
            'format' => ['nullable', 'string', 'in:csv,json'],
            'mark_as_exported' => ['nullable', 'boolean'],
        ];
    }
}
