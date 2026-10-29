<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class RunMrpRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'horizon_start_date' => ['required', 'date'],
            'horizon_end_date' => ['required', 'date', 'after:horizon_start_date'],
            'run_type' => ['nullable', 'string', 'in:regenerative,net_change'],
            'item_ids' => ['nullable', 'array'],
            'item_ids.*' => ['uuid'],
        ];
    }
}
