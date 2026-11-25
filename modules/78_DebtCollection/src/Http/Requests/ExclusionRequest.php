<?php

declare(strict_types=1);

namespace Modules\DebtCollection\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Modules\DebtCollection\Models\Notice;

final class ExclusionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('manage', Notice::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'customer_id' => ['required', 'uuid'],
            'receivable_item_id' => ['nullable', 'uuid'],
            'type' => ['required', Rule::in(['manual', 'dispute', 'legal_hold'])],
            'reason' => ['required', 'string', 'max:500'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
