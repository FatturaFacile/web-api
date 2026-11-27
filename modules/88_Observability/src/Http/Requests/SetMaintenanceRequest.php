<?php

declare(strict_types=1);

namespace Modules\Observability\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class SetMaintenanceRequest extends FormRequest
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
            'maintenance' => ['required', 'boolean'],
            'message' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }
}
