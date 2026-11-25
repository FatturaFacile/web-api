<?php

declare(strict_types=1);

namespace Modules\DebtCollection\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Modules\DebtCollection\Models\Notice;

final class DossierRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('escalate', Notice::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'customer_id' => ['required', 'uuid'],
            'receivable_item_ids' => ['required', 'array', 'min:1'],
            'receivable_item_ids.*' => ['uuid'],
            'office_id' => ['nullable', 'uuid'],
            'locale' => ['nullable', Rule::in(['it', 'en', 'de', 'fr'])],
            'as_of' => ['nullable', 'date_format:Y-m-d'],
        ];
    }
}
