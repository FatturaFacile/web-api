<?php

declare(strict_types=1);

namespace Modules\DebtCollection\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Modules\DebtCollection\Models\Notice;

final class CalculateInterestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('viewAny', Notice::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'receivable_item_id' => ['nullable', 'uuid', 'required_without:amount'],
            'amount' => ['nullable', 'numeric', 'gt:0', 'required_without:receivable_item_id'],
            'due_date' => ['nullable', 'date_format:Y-m-d', 'required_without:receivable_item_id'],
            'currency' => ['nullable', 'string', 'size:3'],
            'jurisdiction' => ['nullable', Rule::in(['CH', 'IT'])],
            'as_of' => ['nullable', 'date_format:Y-m-d'],
            'level' => ['nullable', 'integer', 'between:0,3'],
        ];
    }
}
