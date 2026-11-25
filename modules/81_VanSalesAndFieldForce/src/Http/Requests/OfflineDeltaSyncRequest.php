<?php

declare(strict_types=1);

namespace Modules\VanSalesAndFieldForce\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class OfflineDeltaSyncRequest extends FormRequest
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
            'van_warehouse_id' => ['required', 'uuid'],
            'client_sync_version' => ['nullable', 'integer', 'min:0'],
            'visits' => ['nullable', 'array'],
            'temperature_logs' => ['nullable', 'array'],
            'temperature_logs.*.temp_celsius' => ['required_with:temperature_logs', 'numeric', 'min:-40', 'max:50'],
            'temperature_logs.*.recorded_at' => ['nullable', 'date'],
        ];
    }
}
