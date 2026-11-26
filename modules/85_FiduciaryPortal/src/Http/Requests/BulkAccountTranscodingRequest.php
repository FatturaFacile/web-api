<?php

declare(strict_types=1);

namespace Modules\FiduciaryPortal\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class BulkAccountTranscodingRequest extends FormRequest
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
            'items' => ['required', 'array', 'min:1', 'max:1000'],
            'items.*.source_account_id' => ['required', 'uuid'],
            'items.*.studio_account_code' => ['required', 'string', 'max:60'],
            'items.*.studio_account_name' => ['nullable', 'string', 'max:255'],
            'items.*.studio_vat_code' => ['nullable', 'string', 'max:40'],
            'items.*.studio_cost_center_code' => ['nullable', 'string', 'max:60'],
            'items.*.notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
