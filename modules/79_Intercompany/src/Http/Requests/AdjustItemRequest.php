<?php

declare(strict_types=1);

namespace Modules\Intercompany\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

final class AdjustItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('intercompany.manage');
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:5', 'max:500'],
            'adjustment_amount' => ['required', 'numeric', 'min:-1000000000', 'max:1000000000'],
        ];
    }
}
