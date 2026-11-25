<?php

declare(strict_types=1);

namespace Modules\DebtCollection\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Modules\DebtCollection\Models\Notice;

final class NoticeIndexRequest extends FormRequest
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
            'customer_id' => ['nullable', 'uuid'],
            'status' => ['nullable', Rule::in(['draft', 'queued', 'sent', 'delivered', 'failed', 'cancelled'])],
            'level' => ['nullable', 'integer', 'between:0,3'],
            'kind' => ['nullable', Rule::in(['reminder', 'messa_in_mora'])],
            'batch_id' => ['nullable', 'uuid'],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ];
    }
}
