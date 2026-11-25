<?php

declare(strict_types=1);

namespace Modules\Intercompany\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

final class AsOfDateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('intercompany.view');
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'as_of_date' => ['nullable', 'date'],
            'fiscal_period_id' => ['nullable', 'uuid'],
        ];
    }
}
