<?php

declare(strict_types=1);

namespace Modules\FiduciaryPortal\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class SaveAccountTranscodingRequest extends FormRequest
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
            'studio_system' => ['required', 'string', 'max:40'],
            'source_account_id' => ['required', 'uuid'],
            'studio_account_code' => ['required', 'string', 'max:60'],
            'studio_account_name' => ['nullable', 'string', 'max:255'],
            'studio_vat_code' => ['nullable', 'string', 'max:40'],
            'studio_cost_center_code' => ['nullable', 'string', 'max:60'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
