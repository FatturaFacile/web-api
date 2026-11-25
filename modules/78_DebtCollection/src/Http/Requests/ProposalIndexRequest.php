<?php

declare(strict_types=1);

namespace Modules\DebtCollection\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Modules\DebtCollection\Models\Notice;

final class ProposalIndexRequest extends FormRequest
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
            'as_of' => ['nullable', 'date_format:Y-m-d'],
            'jurisdiction' => ['nullable', Rule::in(['CH', 'IT'])],
            'currency' => ['nullable', 'string', 'size:3'],
            'customer_id' => ['nullable', 'uuid'],
            'level' => ['nullable', 'integer', 'between:0,3'],
            'include_excluded' => ['nullable', 'boolean'],
            'search' => ['nullable', 'string', 'max:100'],
        ];
    }
}
