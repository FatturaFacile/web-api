<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Inventory\Http\Requests\Concerns\ValidatesTenantRecords;

final class RecordCountRequest extends FormRequest
{
    use ValidatesTenantRecords;

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
            'session_line_id' => ['required', 'uuid', $this->tenantExists('inventory_count_lines')],
            'counted_quantity' => ['required', 'numeric', 'min:0'],
            'client_entry_id' => ['sometimes', 'nullable', 'uuid'],
            'device_id' => ['sometimes', 'nullable', 'string', 'max:100'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ];
    }
}
